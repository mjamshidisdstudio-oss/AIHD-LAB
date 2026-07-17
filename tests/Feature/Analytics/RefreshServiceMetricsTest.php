<?php

namespace Tests\Feature\Analytics;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Request;
use App\Models\Result;
use App\Models\Service;
use App\Models\ServiceVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshServiceMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_columns_refresh_is_idempotent(): void
    {
        $service = Service::factory()->create(['vote_up' => 999, 'vote_down' => 999, 'avg_latency_ms' => 1]);

        ServiceVote::factory()->up()->create(['service_id' => $service->id]);
        ServiceVote::factory()->up()->create(['service_id' => $service->id]);
        ServiceVote::factory()->down()->create(['service_id' => $service->id]);

        $order = Order::factory()->completed()->create(['service_id' => $service->id]);
        $request = Request::factory()->create(['order_id' => $order->id]);
        Result::factory()->create(['request_id' => $request->id, 'latency_ms' => 2000]);
        $order2 = Order::factory()->completed()->create(['service_id' => $service->id]);
        $request2 = Request::factory()->create(['order_id' => $order2->id]);
        Result::factory()->create(['request_id' => $request2->id, 'latency_ms' => 4000]);

        $this->artisan('services:refresh-metrics')->assertSuccessful();

        $service->refresh();
        $this->assertSame(2, $service->vote_up);
        $this->assertSame(1, $service->vote_down);
        $this->assertSame(3000, $service->avg_latency_ms);

        // Rerun against unchanged source data must not drift.
        $this->artisan('services:refresh-metrics')->assertSuccessful();
        $service->refresh();
        $this->assertSame(2, $service->vote_up);
        $this->assertSame(1, $service->vote_down);
        $this->assertSame(3000, $service->avg_latency_ms);
    }

    public function test_avg_latency_stays_null_with_no_completed_orders(): void
    {
        $service = Service::factory()->create(['avg_latency_ms' => null]);

        // A processing (not completed) order's result must not count.
        $order = Order::factory()->create(['service_id' => $service->id, 'status' => OrderStatus::Processing]);
        $request = Request::factory()->create(['order_id' => $order->id]);
        Result::factory()->create(['request_id' => $request->id, 'latency_ms' => 5000]);

        $this->artisan('services:refresh-metrics')->assertSuccessful();

        $service->refresh();
        $this->assertNull($service->avg_latency_ms);
    }

    public function test_admin_preview_orders_are_excluded_from_avg_latency(): void
    {
        $service = Service::factory()->create();

        $order = Order::factory()->completed()->create([
            'service_id' => $service->id,
            'source' => OrderSource::AdminPreview,
        ]);
        $request = Request::factory()->create(['order_id' => $order->id]);
        Result::factory()->create(['request_id' => $request->id, 'latency_ms' => 9999]);

        $this->artisan('services:refresh-metrics')->assertSuccessful();

        $service->refresh();
        $this->assertNull($service->avg_latency_ms);
    }
}
