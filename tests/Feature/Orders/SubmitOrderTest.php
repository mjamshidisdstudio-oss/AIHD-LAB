<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Actions\Orders\SubmitOrder;
use App\Contracts\CoinService;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceInputType;
use App\Enums\ServiceOutputType;
use App\Exceptions\Orders\ServiceUnavailableForOrdersException;
use App\Jobs\DispatchRequest;
use App\Models\Order;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SubmitOrderTest extends TestCase
{
    use RefreshDatabase;

    private function publishedService(): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => 2,
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->required()->create([
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

    public function test_submit_writes_order_inputs_and_a_queued_request_then_dispatches_after_commit(): void
    {
        Queue::fake();
        $service = $this->publishedService();

        $order = app(SubmitOrder::class)->handle($service, 'user-1', ['prompt' => 'a cosy cabin']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Processing->value, 'coins_charged' => 2]);
        $this->assertDatabaseHas('order_inputs', ['order_id' => $order->id, 'value_text' => 'a cosy cabin']);
        $this->assertDatabaseHas('requests', ['order_id' => $order->id, 'attempt_no' => 1, 'status' => RequestStatus::Queued->value]);

        // Dispatched after commit — the request it targets exists.
        Queue::assertPushed(DispatchRequest::class, function (DispatchRequest $job) use ($order) {
            return $job->request->order_id === $order->id
                && Request::whereKey($job->request->id)->exists();
        });
    }

    /**
     * Never Again: coins are charged BEFORE the transaction, so a rollback must
     * refund them, leave no order / input / request behind, and enqueue no ghost
     * dispatch job.
     */
    public function test_a_rolled_back_submit_refunds_coins_and_leaves_no_order_or_ghost_job(): void
    {
        Queue::fake();

        // Spy the wallet: exactly one charge, then exactly one compensating refund.
        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('charge')->once()->andReturn('txn-abc');
        $coins->shouldReceive('refund')->once()->with('txn-abc');
        $this->app->instance(CoinService::class, $coins);

        $service = $this->publishedService();

        // Missing the required "prompt" answer => the transaction throws and rolls back.
        try {
            app(SubmitOrder::class)->handle($service, 'user-1', []);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Request::count());
        $this->assertDatabaseCount('order_inputs', 0);
        Queue::assertNotPushed(DispatchRequest::class);
        // Mockery verifies charge(1) + refund(1) on tearDown.
    }

    public function test_submitting_a_service_with_no_published_version_is_rejected(): void
    {
        $service = Service::factory()->create(['current_version_id' => null]);

        $this->expectException(ServiceUnavailableForOrdersException::class);

        app(SubmitOrder::class)->handle($service, 'user-1', []);
    }
}
