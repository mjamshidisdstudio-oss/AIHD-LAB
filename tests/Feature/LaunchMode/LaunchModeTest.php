<?php

namespace Tests\Feature\LaunchMode;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Enums\OrderStatus;
use App\Enums\ServiceInputType;
use App\Enums\ServiceOutputType;
use App\Http\Middleware\AnonymousAuth;
use App\Jobs\DispatchRequest;
use App\Jobs\PollRequestResult;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\User;
use App\Services\Coins\CoreCoinService;
use App\Services\Coins\NullCoinService;
use App\Services\Ingest\PollRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * Phase L2: LAB_BILLING_ENABLED and LAB_AUTH_MODE let the platform launch
 * independently of the core team's billing/identity endpoints, without
 * removing either real implementation -- both are flags, reversible with no
 * code change, not a rewrite.
 */
class LaunchModeTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

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
            'type' => ServiceOutputType::Text,
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    /**
     * Never Again: with billing disabled, CoinService resolves to
     * NullCoinService and a real order still runs end to end -- SubmitOrder
     * still calls deduct()/settle() exactly as it always does (no code path
     * change there), but the call is a genuine no-op, not skipped by a
     * special case. Uses a deliberately nonzero coin_cost to prove the
     * catalog price is irrelevant, not coincidentally already free.
     */
    public function test_billing_disabled_skips_deduct_and_completes_order(): void
    {
        config(['lab.billing_enabled' => false]);
        $this->assertInstanceOf(NullCoinService::class, app(CoinService::class));

        Storage::fake('media');
        Queue::fake([DispatchRequest::class, PollRequestResult::class]);
        Http::fake([
            'external.test/generate' => Http::response(['external_order_id' => 'ext-1', 'status' => 'accepted']),
            'external.test/result*' => Http::response([
                'status' => 'completed',
                'latency_ms' => 400,
                'results' => [['result_number' => 1, 'type' => 'text', 'text' => 'a cosy cabin at dusk']],
            ]),
        ]);

        $service = $this->publishedService(coinCost: 5);
        $headers = $this->coreUserHeaders('user-1');

        $orderId = $this->withHeaders($headers)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'a cosy cabin'],
        ])->assertStatus(202)->json('data.id');

        $order = Order::findOrFail($orderId);
        // The catalog's nominal price is still recorded on the order (an
        // honest historical record of what the service was listed at) --
        // but nothing was actually charged: balance is unlimited because
        // nothing ever really held funds against it.
        $this->assertSame(5, $order->coins_charged);
        $this->assertSame(PHP_INT_MAX, app(CoinService::class)->balance('user-1'));

        $request = $order->requests()->firstOrFail();
        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));
        $request->refresh();
        app(PollRequest::class)->handle($request);
        $order->refresh();

        // settle() being a no-op too never blocked completion.
        $this->assertSame(OrderStatus::Completed, $order->status);
    }

    /**
     * Never Again: flipping the flag back on, in the same process, restores
     * the real CoinService -- proving the reversibility is genuine (a live
     * config change), not just "the default happens to be real".
     */
    public function test_flipping_billing_back_on_restores_deduct(): void
    {
        config(['lab.billing_enabled' => false]);
        $this->assertInstanceOf(NullCoinService::class, app(CoinService::class));

        config(['lab.billing_enabled' => true]);
        $this->assertInstanceOf(CoreCoinService::class, app(CoinService::class));
    }

    /**
     * Never Again: AnonymousAuth resolves a user_ref exactly like
     * AuthenticateWithCoreToken does (via $request->userRef()), and that
     * identity is stable across requests from the same browser -- the
     * signed cookie it sets on first contact is honored on the next
     * request, not regenerated.
     */
    public function test_anonymous_auth_resolves_stable_user_ref_per_visitor(): void
    {
        $middleware = new AnonymousAuth;

        $firstUserRef = null;
        $firstRequest = Request::create('/api/marketplace/services', 'GET');
        $firstResponse = $middleware->handle($firstRequest, function (Request $req) use (&$firstUserRef): Response {
            $firstUserRef = $req->userRef();

            return new Response('ok');
        });

        $this->assertNotNull($firstUserRef);
        $this->assertStringStartsWith('anon-', $firstUserRef);

        $cookies = $firstResponse->headers->getCookies();
        $this->assertCount(1, $cookies, 'expected exactly one cookie to be set on a new visitor\'s first request');
        $cookie = $cookies[0];
        $this->assertSame('aihd_anon_id', $cookie->getName());

        // Replay that exact cookie, as the same browser's next request would.
        $secondUserRef = null;
        $secondRequest = Request::create('/api/marketplace/services', 'GET');
        $secondRequest->cookies->set('aihd_anon_id', $cookie->getValue());
        $secondResponse = $middleware->handle($secondRequest, function (Request $req) use (&$secondUserRef): Response {
            $secondUserRef = $req->userRef();

            return new Response('ok');
        });

        $this->assertSame($firstUserRef, $secondUserRef, 'expected the same browser to resolve to the same user_ref, not a fresh one');
        // Already-identified visitors don't need a new cookie re-issued.
        $this->assertCount(0, $secondResponse->headers->getCookies());

        // A tampered cookie must never be trusted as someone else's identity
        // -- treated as a new visitor, not a security bypass.
        $tamperedUserRef = null;
        $tamperedRequest = Request::create('/api/marketplace/services', 'GET');
        $tamperedRequest->cookies->set('aihd_anon_id', substr($cookie->getValue(), 0, -1).'X');
        $middleware->handle($tamperedRequest, function (Request $req) use (&$tamperedUserRef): Response {
            $tamperedUserRef = $req->userRef();

            return new Response('ok');
        });
        $this->assertNotSame($firstUserRef, $tamperedUserRef);
    }

    /**
     * Never Again: 'auth.core' always resolves to SiteAuth, a thin gate that
     * reads config('lab.auth_mode') at request time rather than bootstrap/
     * app.php deciding once at boot -- so flipping the flag genuinely swaps
     * live traffic between AnonymousAuth and AuthenticateWithCoreToken in the
     * same process, not just at the next deploy.
     */
    public function test_flipping_auth_mode_back_on_restores_core_token_requirement(): void
    {
        config(['lab.auth_mode' => 'anonymous']);
        // No token at all, yet allowed through -- anonymous mode issues an
        // identity instead of demanding one.
        $this->getJson('/api/marketplace/services')->assertOk();

        config(['lab.auth_mode' => 'core']);
        // Same request, same missing token -- now rejected, because the
        // gate is back to requiring a real core bearer token.
        $this->getJson('/api/marketplace/services')->assertUnauthorized();
    }

    /**
     * Never Again: the admin API's Sanctum guard (auth:sanctum +
     * can:manage-catalog) is a completely separate authentication system
     * from the site-order 'auth.core' alias LAB_AUTH_MODE affects --
     * switching to anonymous mode must never weaken or bypass it.
     */
    public function test_admin_sanctum_auth_unaffected_by_lab_auth_mode(): void
    {
        config(['lab.auth_mode' => 'anonymous']);

        // No Sanctum session at all -- still rejected exactly as always.
        $this->getJson('/api/admin/services')->assertUnauthorized();

        // A real admin session still works normally.
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/services')->assertOk();
    }
}
