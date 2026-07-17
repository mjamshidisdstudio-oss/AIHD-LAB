<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Actions\Orders\SubmitOrder;
use App\Contracts\CoinService;
use App\Enums\OrderSource;
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
use App\Services\Coins\MockCoinService;
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
        $this->app->instance(CoinService::class, new MockCoinService);
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
     * Never Again: exactly one deduct per submit, and the resulting txn_ref is
     * persisted on the order (needed later to settle/refund).
     */
    public function test_submit_deducts_once_and_stores_txn_ref(): void
    {
        Queue::fake();

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('deduct')->once()->with('user-1', 2, Mockery::type('string'))->andReturn('txn-xyz');
        $this->app->instance(CoinService::class, $coins);

        $service = $this->publishedService();

        $order = app(SubmitOrder::class)->handle($service, 'user-1', ['prompt' => 'a cosy cabin']);

        $this->assertSame('txn-xyz', $order->refresh()->coin_txn_ref);
        $this->assertSame(2, $order->coins_charged);
        // Mockery verifies deduct(1) on tearDown.
    }

    /**
     * Never Again: coins are deducted BEFORE the transaction, so a rollback must
     * trigger a compensating refund and leave no order / input / request behind,
     * with no ghost dispatch job.
     */
    public function test_rolled_back_transaction_after_deduct_triggers_compensating_refund(): void
    {
        Queue::fake();

        // Spy the wallet: exactly one deduct, then exactly one compensating refund.
        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('deduct')->once()->andReturn('txn-abc');
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
        // Mockery verifies deduct(1) + refund(1) on tearDown.
    }

    /**
     * Never Again: admin-preview orders exercise a version without charging a
     * real customer — no deduct call is ever made, and nothing is stored to
     * later settle/refund.
     */
    public function test_admin_preview_orders_are_coin_free(): void
    {
        Queue::fake();

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldNotReceive('deduct');
        $coins->shouldNotReceive('refund');
        $this->app->instance(CoinService::class, $coins);

        $service = $this->publishedService();

        $order = app(SubmitOrder::class)->handle(
            $service,
            'user-1',
            ['prompt' => 'a cosy cabin'],
            [],
            ['source' => OrderSource::AdminPreview],
        );

        $this->assertSame(OrderSource::AdminPreview, $order->source);
        $this->assertSame(0, $order->coins_charged);
        $this->assertNull($order->coin_txn_ref);
    }

    /**
     * Never Again: admin preview is the one caller allowed to run a draft
     * version explicitly — the whole point is to exercise it before it is
     * ever published. A real (site) order against the same draft must still
     * be rejected.
     */
    public function test_admin_preview_can_run_an_explicit_draft_version(): void
    {
        Queue::fake();
        $this->app->instance(CoinService::class, new MockCoinService);

        $service = Service::factory()->create();
        $draft = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $draft->id,
            'slug' => 'prompt',
        ]);
        ServiceOutput::factory()->create([
            'service_version_id' => $draft->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);

        $order = app(SubmitOrder::class)->handle(
            $service,
            'admin:1',
            ['prompt' => 'try the draft'],
            [],
            ['source' => OrderSource::AdminPreview],
            $draft,
        );

        $this->assertSame($draft->id, $order->service_version_id);
        $this->assertSame(0, $order->coins_charged);

        $this->expectException(ServiceUnavailableForOrdersException::class);
        app(SubmitOrder::class)->handle($service, 'user-1', ['prompt' => 'x'], [], [], $draft);
    }

    public function test_submitting_a_service_with_no_published_version_is_rejected(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = Service::factory()->create(['current_version_id' => null]);

        $this->expectException(ServiceUnavailableForOrdersException::class);

        app(SubmitOrder::class)->handle($service, 'user-1', []);
    }
}
