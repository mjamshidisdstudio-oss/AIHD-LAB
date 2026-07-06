<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\ExternalServiceClient;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceOutputType;
use App\Jobs\DispatchRequest;
use App\Jobs\PollRequestResult;
use App\Models\Order;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Never Again: DispatchRequest must be idempotent. Running it twice for the same
 * request must make exactly ONE external submit call and leave exactly one
 * queued -> awaiting transition. A double dispatch (retry, at-least-once queue)
 * must never submit the order twice.
 */
class DispatchRequestIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function queuedRequest(): Request
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);
        app(PublishVersion::class)->handle($version);

        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
        ]);

        return Request::factory()->create([
            'order_id' => $order->id,
            'attempt_no' => 1,
            'status' => RequestStatus::Queued,
            'external_order_id' => null,
        ]);
    }

    public function test_running_dispatch_twice_makes_one_external_call_and_one_transition(): void
    {
        Queue::fake([PollRequestResult::class]);
        Http::fake([
            '*' => Http::response(['external_order_id' => 'ext-123', 'status' => 'accepted']),
        ]);

        $request = $this->queuedRequest();

        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));
        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));

        // Exactly one submit to the external provider.
        Http::assertSentCount(1);

        // One transition: the request is awaiting with the external id set.
        $request->refresh();
        $this->assertSame(RequestStatus::Awaiting, $request->status);
        $this->assertSame('ext-123', $request->external_order_id);
    }
}
