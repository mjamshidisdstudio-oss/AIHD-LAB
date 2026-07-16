<?php

namespace Tests\Feature\Orders;

use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Jobs\PollRequestResult;
use App\Models\Order;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceVersion;
use App\Support\External\ExternalResult;
use App\Support\External\ExternalResultItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The back half of the coin lifecycle: exactly one of settle()/refund() is
 * ever called per transaction reference, and never either for a coin-free
 * admin-preview order. SubmitOrderTest covers the front half (deduct + the
 * rollback compensating refund).
 */
class CoinLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function requestAwaiting(array $orderOverrides = [], array $versionOverrides = []): Request
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(array_merge([
            'service_id' => $service->id,
            'max_get_attempts' => 5,
            'get_interval_s' => 1,
        ], $versionOverrides));

        $order = Order::factory()->create(array_merge([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
        ], $orderOverrides));

        return Request::factory()->create([
            'order_id' => $order->id,
            'attempt_no' => 1,
            'status' => RequestStatus::Awaiting,
            'get_poll_count' => 0,
        ]);
    }

    /**
     * Never Again: once the order actually completes, settle() finalizes the
     * held deduct exactly once — never refund().
     */
    public function test_completion_settles_exactly_once(): void
    {
        $request = $this->requestAwaiting(['coin_txn_ref' => 'txn-123']);

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('poll')->once()->andReturn(new ExternalResult(
            [new ExternalResultItem(resultNumber: 1, type: 'text', text: 'done')],
            latencyMs: 500,
        ));

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('settle')->once()->with('txn-123');
        $coins->shouldNotReceive('refund');

        (new PollRequestResult($request))->handle($client, $coins);

        $this->assertSame(RequestStatus::Completed, $request->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $request->order->refresh()->status);
        // Mockery verifies settle(1) + refund(0) on tearDown.
    }

    /**
     * Never Again: a timed-out request (max_get_attempts reached with no
     * result) fails and refunds the held deduct exactly once — never settle().
     */
    public function test_failure_refunds_exactly_once(): void
    {
        $request = $this->requestAwaiting(
            orderOverrides: ['coin_txn_ref' => 'txn-456'],
            versionOverrides: ['max_get_attempts' => 1],
        );

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('poll')->once()->andReturn(null);

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('refund')->once()->with('txn-456');
        $coins->shouldNotReceive('settle');

        (new PollRequestResult($request))->handle($client, $coins);

        $this->assertSame(RequestStatus::Failed, $request->refresh()->status);
        $this->assertSame(OrderStatus::Failed, $request->order->refresh()->status);
        // Mockery verifies refund(1) + settle(0) on tearDown.
    }

    /**
     * Never Again: an admin-preview order never deducted, so completion must
     * never call settle() (there is nothing to finalize).
     */
    public function test_admin_preview_completion_never_settles(): void
    {
        $request = $this->requestAwaiting(['coin_txn_ref' => null, 'coins_charged' => 0]);

        $client = Mockery::mock(ExternalServiceClient::class);
        $client->shouldReceive('poll')->once()->andReturn(new ExternalResult(
            [new ExternalResultItem(resultNumber: 1, type: 'text', text: 'done')],
            latencyMs: 500,
        ));

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldNotReceive('settle');
        $coins->shouldNotReceive('refund');

        (new PollRequestResult($request))->handle($client, $coins);

        $this->assertSame(OrderStatus::Completed, $request->order->refresh()->status);
    }
}
