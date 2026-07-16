<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
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
use App\Services\Coins\MockCoinService;
use App\Services\Ingest\PollRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * The end-to-end walking skeleton: one order flowing submit -> dispatch ->
 * (mock) service -> poll -> result, observed transition by transition, with
 * results persisted to the media disk and read back from our own database.
 */
class WalkingSkeletonTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedService(): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => 2,
            'get_interval_s' => 1,
            'max_get_attempts' => 5,
            'post_url' => 'http://external.test/generate',
            'get_url' => 'http://external.test/result',
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $version->id,
            'slug' => 'prompt',
        ]);
        ServiceOutput::factory()->create(['service_version_id' => $version->id, 'result_number' => 1, 'type' => ServiceOutputType::Image]);
        ServiceOutput::factory()->create(['service_version_id' => $version->id, 'result_number' => 2, 'type' => ServiceOutputType::Text]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    public function test_order_flows_submit_dispatch_poll_completed_and_reads_back(): void
    {
        Storage::fake('media');
        Queue::fake([DispatchRequest::class, PollRequestResult::class]);
        Http::fake([
            'external.test/generate' => Http::response(['external_order_id' => 'ext-1', 'status' => 'accepted']),
            'external.test/result*' => Http::response([
                'status' => 'completed',
                'latency_ms' => 500,
                'results' => [
                    ['result_number' => 1, 'type' => 'image', 'mime' => 'image/png', 'content_base64' => base64_encode('IMG-BYTES')],
                    ['result_number' => 2, 'type' => 'text', 'text' => 'a cosy cabin at dusk'],
                ],
            ]),
        ]);

        // Coins are exercised in isolation by SubmitOrderTest/CoinLifecycleTest;
        // here we only care about counting calls to the external service.
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = $this->publishedService();
        $headers = $this->coreUserHeaders('user-1');

        // 1. Submit -> order processing, request queued, dispatch enqueued.
        $orderId = $this->withHeaders($headers)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'a cosy cabin'],
        ])->assertStatus(202)->json('data.id');

        $order = Order::findOrFail($orderId);
        $request = $order->requests()->firstOrFail();
        $this->assertSame(RequestStatus::Queued, $request->status);
        Queue::assertPushed(DispatchRequest::class);

        // 2. Dispatch -> awaiting (one external submit).
        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));
        $request->refresh();
        $this->assertSame(RequestStatus::Awaiting, $request->status);
        $this->assertSame('ext-1', $request->external_order_id);

        // 3. Poll -> completed, results persisted.
        app(PollRequest::class)->handle($request);
        $request->refresh();
        $order->refresh();
        $this->assertSame(RequestStatus::Completed, $request->status);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);

        // Text result + image result (file written to the media disk).
        $this->assertDatabaseHas('results', ['request_id' => $request->id, 'result_number' => 2, 'text_value' => 'a cosy cabin at dusk']);
        $imageResult = Result::where('request_id', $request->id)->where('result_number', 1)->firstOrFail();
        $this->assertNotNull($imageResult->file_id);
        $this->assertSame('media', $imageResult->file->disk);
        Storage::disk('media')->assertExists($imageResult->file->path);

        // 4. Read back from our DB only.
        $this->withHeaders($headers)->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.requests.0.status', 'completed')
            ->assertJsonCount(2, 'data.requests.0.results');

        // Exactly one submit + one poll to the external service.
        Http::assertSentCount(2);
    }
}
