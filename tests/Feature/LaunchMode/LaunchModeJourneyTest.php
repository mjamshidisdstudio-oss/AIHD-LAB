<?php

namespace Tests\Feature\LaunchMode;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Enums\InteractionKind;
use App\Enums\OrderStatus;
use App\Enums\ServiceInputType;
use App\Enums\ServiceOutputType;
use App\Jobs\DispatchRequest;
use App\Jobs\PollRequestResult;
use App\Models\Order;
use App\Models\Result;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Services\Ingest\PollRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeneratesFakeMedia;
use Tests\TestCase;

/**
 * Phase L2 exit criteria, proven over real HTTP through the actual
 * bootstrap/app.php wiring (not a direct middleware unit test): with both
 * launch-mode flags flipped, a no-login visitor runs a paid-looking service
 * end to end, downloads a result, votes, and bookmarks -- all attributed to
 * one stable anonymous user_ref, with zero coins ever actually held.
 *
 * 'auth.core' always resolves to SiteAuth, a thin gate that reads
 * config('lab.auth_mode') at request time (see that class) -- so a plain
 * config() call here is genuinely live, the same as LAB_BILLING_ENABLED.
 */
class LaunchModeJourneyTest extends TestCase
{
    use GeneratesFakeMedia, RefreshDatabase;

    private function publishedService(int $coinCost): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => $coinCost,
            'post_url' => 'http://external.test/generate',
            'get_url' => 'http://external.test/result',
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $version->id,
            'slug' => 'prompt',
        ]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Image,
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    public function test_anonymous_no_login_visitor_completes_full_journey_with_zero_coins(): void
    {
        config(['lab.auth_mode' => 'anonymous', 'lab.billing_enabled' => false]);

        Storage::fake('media');
        Queue::fake([DispatchRequest::class, PollRequestResult::class]);
        Http::fake([
            'external.test/generate' => Http::response(['external_order_id' => 'ext-1', 'status' => 'accepted']),
            'external.test/result*' => Http::response([
                'status' => 'completed',
                'latency_ms' => 400,
                'results' => [
                    ['result_number' => 1, 'type' => 'image', 'mime' => 'image/png', 'content_base64' => $this->fakePngBase64()],
                ],
            ]),
        ]);

        // Deliberately nonzero: proves the visitor is never actually charged,
        // not that the catalog happened to already be free.
        $service = $this->publishedService(coinCost: 5);

        // First contact, no cookie at all -- a brand new anonymous visitor.
        $firstHit = $this->getJson('/api/marketplace/services')->assertOk();
        $cookies = $firstHit->headers->getCookies();
        $this->assertCount(1, $cookies, 'expected a fresh anonymous id cookie on first contact');
        $anonCookie = $cookies[0];
        $this->assertSame('aihd_anon_id', $anonCookie->getName());

        // withCredentials() mirrors a real browser's `credentials: 'include'`
        // -- without it the test client's JSON helpers (postJson et al) never
        // send cookies at all. withUnencryptedCookie (not withCookie) matches
        // reality too: this cookie is never processed by EncryptCookies,
        // since the 'api' middleware group doesn't carry it.
        $this->withCredentials();
        $asVisitor = fn () => $this->withUnencryptedCookie('aihd_anon_id', $anonCookie->getValue());

        // Submit -- no bearer token, no login, just the anonymous cookie.
        $orderId = $asVisitor()->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'a cosy cabin'],
        ])->assertStatus(202)->json('data.id');

        $order = Order::findOrFail($orderId);
        $this->assertStringStartsWith('anon-', $order->user_ref);
        // The catalog price is still honestly recorded...
        $this->assertSame(5, $order->coins_charged);
        // ...but nothing was ever really held: balance is unlimited because
        // CoinService resolved to NullCoinService for this whole request.
        $this->assertSame(PHP_INT_MAX, app(CoinService::class)->balance($order->user_ref));

        // Run the pipeline for real (same pattern as WalkingSkeletonTest).
        $request = $order->requests()->firstOrFail();
        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));
        $request->refresh();
        app(PollRequest::class)->handle($request);
        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);

        $result = Result::where('request_id', $request->id)->where('result_number', 1)->firstOrFail();
        Storage::disk('media')->assertExists($result->file->path);

        // Download, vote, and bookmark -- all as the same anonymous visitor,
        // all still just the cookie, never a bearer token.
        $asVisitor()->get("/api/marketplace/results/{$result->id}/download")->assertOk();
        $asVisitor()->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1])->assertOk();
        $asVisitor()->postJson("/api/marketplace/services/{$service->id}/bookmark")->assertOk();

        $this->assertDatabaseHas('interactions', [
            'result_id' => $result->id,
            'user_ref' => $order->user_ref,
            'kind' => InteractionKind::Download->value,
        ]);
        $this->assertDatabaseHas('service_votes', [
            'service_id' => $service->id,
            'user_ref' => $order->user_ref,
            'value' => 1,
        ]);
        $this->assertDatabaseHas('bookmarks', [
            'service_id' => $service->id,
            'user_ref' => $order->user_ref,
        ]);
    }
}
