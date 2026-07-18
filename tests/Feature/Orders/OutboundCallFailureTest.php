<?php

namespace Tests\Feature\Orders;

use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Enums\FailureStage;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceOutputType;
use App\Exceptions\External\ExternalServiceReportedFailureException;
use App\Jobs\DispatchRequest;
use App\Models\Order;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Services\Ingest\PollRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Tests\TestCase;

/**
 * Never Again: an outbound HTTP call to the external provider must never
 * leave a request/order hanging. A submit() failure must fail the request
 * exactly like any other failure (refund + strike), and a poll() failure
 * must still count toward the attempt budget so a permanently-unreachable
 * service converges to Timeout instead of polling forever.
 */
class OutboundCallFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_submit_failure_fails_request_and_refunds_coins(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);
        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
            'coin_txn_ref' => 'txn-submit-fail',
        ]);
        $request = Request::factory()->create([
            'order_id' => $order->id,
            'status' => RequestStatus::Queued,
        ]);

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('submit')->once()->andThrow(new ConnectionException('Connection timed out.'));
        $this->app->instance(ExternalServiceClient::class, $client);

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('refund')->once()->with('txn-submit-fail');
        $coins->shouldNotReceive('settle');
        $this->app->instance(CoinService::class, $coins);

        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));

        $request->refresh();
        $this->assertSame(RequestStatus::Failed, $request->status);
        $this->assertSame(FailureStage::Post, $request->failure_stage);
        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
        $this->assertSame(1, $service->refresh()->consecutive_failures);
    }

    public function test_poll_failure_counts_toward_attempt_budget_and_does_not_throw(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id, 'max_get_attempts' => 5]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);
        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
        ]);
        $request = Request::factory()->create([
            'order_id' => $order->id,
            'status' => RequestStatus::Awaiting,
            'get_poll_count' => 0,
        ]);

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('poll')->once()->andThrow(new ConnectionException('Connection timed out.'));
        $this->app->instance(ExternalServiceClient::class, $client);

        app(PollRequest::class)->handle($request);

        $request->refresh();
        $this->assertSame(1, $request->get_poll_count);
        $this->assertSame(RequestStatus::Polling, $request->status);
        $this->assertSame(OrderStatus::Processing, $order->refresh()->status);
    }

    public function test_repeated_poll_failures_eventually_time_out(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id, 'max_get_attempts' => 2]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);
        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
            'coin_txn_ref' => null,
        ]);
        $request = Request::factory()->create([
            'order_id' => $order->id,
            'status' => RequestStatus::Awaiting,
            'get_poll_count' => 0,
        ]);

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('poll')->twice()->andThrow(new ConnectionException('Connection timed out.'));
        $this->app->instance(ExternalServiceClient::class, $client);

        $poller = app(PollRequest::class);
        $poller->handle($request); // attempt 1 -> failed poll, count=1
        $poller->handle($request->refresh()); // attempt 2 -> failed poll, count=2
        $poller->handle($request->refresh()); // budget spent -> Timeout, no further poll() call

        $this->assertSame(RequestStatus::Failed, $request->refresh()->status);
        $this->assertSame(FailureStage::Timeout, $request->failure_stage);
    }

    /**
     * Never Again: FailureStage::Service existed only in tests that called
     * FailRequest directly -- nothing in the real poll path could ever
     * reach it, because poll() treated ANY non-"completed" status
     * (including a provider explicitly reporting failure) as "still
     * pending". An explicit failure report must fail the request
     * IMMEDIATELY with FailureStage::Service, not be mistaken for "not done
     * yet" and left to grind through the attempt budget toward a
     * misleading Timeout.
     */
    public function test_poll_reported_failure_immediately_fails_with_service_stage(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id, 'max_get_attempts' => 5]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);
        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
            'coin_txn_ref' => 'txn-reported-fail',
        ]);
        $request = Request::factory()->create([
            'order_id' => $order->id,
            'status' => RequestStatus::Awaiting,
            'get_poll_count' => 0,
        ]);

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('poll')->once()->andThrow(ExternalServiceReportedFailureException::reported('model unavailable'));
        $this->app->instance(ExternalServiceClient::class, $client);

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('refund')->once()->with('txn-reported-fail');
        $coins->shouldNotReceive('settle');
        $this->app->instance(CoinService::class, $coins);

        app(PollRequest::class)->handle($request);

        $request->refresh();
        $this->assertSame(RequestStatus::Failed, $request->status);
        $this->assertSame(FailureStage::Service, $request->failure_stage);
        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
        $this->assertSame(1, $service->refresh()->consecutive_failures);
    }
}
