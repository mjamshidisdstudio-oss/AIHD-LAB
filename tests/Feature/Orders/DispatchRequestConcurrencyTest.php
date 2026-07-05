<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\ExternalServiceClient;
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

class DispatchRequestConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function publishedService(int $maxConcurrent): Service
    {
        $service = Service::factory()->create(['max_concurrent' => $maxConcurrent]);
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    private function requestFor(Service $service, RequestStatus $status): Request
    {
        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $service->current_version_id,
        ]);

        return Request::factory()->create([
            'order_id' => $order->id,
            'attempt_no' => 1,
            'status' => $status,
            'external_order_id' => $status === RequestStatus::Queued ? null : 'ext-existing',
        ]);
    }

    public function test_dispatch_releases_without_submitting_when_service_is_at_capacity(): void
    {
        Http::fake(['*' => Http::response(['external_order_id' => 'x', 'status' => 'accepted'])]);

        $service = $this->publishedService(maxConcurrent: 1);
        // One request already in flight fills the single slot.
        $this->requestFor($service, RequestStatus::Awaiting);
        $queued = $this->requestFor($service, RequestStatus::Queued);

        (new DispatchRequest($queued))->handle(app(ExternalServiceClient::class));

        // At cap: nothing submitted, and the request is kept queued (released,
        // not dropped) for a later attempt.
        Http::assertNothingSent();
        $this->assertSame(RequestStatus::Queued, $queued->refresh()->status);
    }

    public function test_dispatch_admits_when_below_capacity(): void
    {
        Queue::fake([PollRequestResult::class]);
        Http::fake(['*' => Http::response(['external_order_id' => 'ext-9', 'status' => 'accepted'])]);

        $service = $this->publishedService(maxConcurrent: 2);
        // One in flight, cap 2 — room for one more.
        $this->requestFor($service, RequestStatus::Awaiting);
        $queued = $this->requestFor($service, RequestStatus::Queued);

        (new DispatchRequest($queued))->handle(app(ExternalServiceClient::class));

        Http::assertSentCount(1);
        $this->assertSame(RequestStatus::Awaiting, $queued->refresh()->status);
        $this->assertSame('ext-9', $queued->refresh()->external_order_id);
    }
}
