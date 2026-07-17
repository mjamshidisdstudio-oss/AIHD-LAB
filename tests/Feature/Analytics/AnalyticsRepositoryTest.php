<?php

namespace Tests\Feature\Analytics;

use App\Enums\EntryMode;
use App\Enums\InteractionKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Request;
use App\Models\Result;
use App\Models\Service;
use App\Models\ServiceVersion;
use App\Models\ServiceVote;
use App\Services\Analytics\AnalyticsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsRepository $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = app(AnalyticsRepository::class);
    }

    public function test_analytics_exclude_admin_preview_orders(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);

        Order::factory()->completed()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'source' => OrderSource::Site,
        ]);
        Order::factory()->completed()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'source' => OrderSource::AdminPreview,
        ]);

        $ladder = $this->analytics->interestLadder($service->id);
        $this->assertSame(1, $ladder['overall']['generate']);
        $this->assertSame(1, $ladder['overall']['complete']);

        $comparison = $this->analytics->versionComparison($service->id)->first();
        $this->assertSame(1, $comparison['orders']);
        $this->assertSame(1, $comparison['completed']);

        $funnel = $this->analytics->entryModeFunnel($service->id)->firstWhere('entry_mode', 'wizard');
        $this->assertSame(1, $funnel['orders']);
    }

    public function test_interest_ladder_counts_every_rung(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);

        $completedOrder = Order::factory()->completed()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
        ]);
        $request = Request::factory()->create(['order_id' => $completedOrder->id]);
        $result = Result::factory()->create(['request_id' => $request->id]);

        Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
        ]);

        $regeneration = Order::factory()->completed()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'regenerated_from_order_id' => $completedOrder->id,
            'root_order_id' => $completedOrder->id,
        ]);

        Interaction::factory()->create([
            'kind' => InteractionKind::Download,
            'service_id' => $service->id,
            'order_id' => $completedOrder->id,
            'result_id' => $result->id,
        ]);

        ServiceVote::factory()->up()->create(['service_id' => $service->id, 'service_version_id' => $version->id]);
        ServiceVote::factory()->up()->create(['service_id' => $service->id, 'service_version_id' => $version->id]);
        ServiceVote::factory()->down()->create(['service_id' => $service->id, 'service_version_id' => $version->id]);

        $ladder = $this->analytics->interestLadder($service->id);

        $this->assertSame(3, $ladder['overall']['generate']);
        $this->assertSame(2, $ladder['overall']['complete']);
        $this->assertSame(1, $ladder['overall']['download']);
        $this->assertSame(1, $ladder['overall']['regenerate']);
        $this->assertSame(2, $ladder['overall']['vote_up']);
        $this->assertSame(1, $ladder['overall']['vote_down']);

        $byVersion = $ladder['by_version']->firstWhere('version_id', $version->id);
        $this->assertSame(3, $byVersion['generate']);
    }

    public function test_version_comparison_shows_v2_beating_v1(): void
    {
        $service = Service::factory()->create();
        $v1 = ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 1]);
        $v2 = ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 2]);

        // v1: 1 of 2 orders complete, slow.
        Order::factory()->completed()->create(['service_id' => $service->id, 'service_version_id' => $v1->id]);
        Order::factory()->failed()->create(['service_id' => $service->id, 'service_version_id' => $v1->id]);
        $v1Order = Order::query()->where('service_version_id', $v1->id)->where('status', 'completed')->first();
        $v1Request = Request::factory()->create(['order_id' => $v1Order->id]);
        Result::factory()->create(['request_id' => $v1Request->id, 'latency_ms' => 8000]);

        // v2: 2 of 2 orders complete, faster.
        $v2OrderA = Order::factory()->completed()->create(['service_id' => $service->id, 'service_version_id' => $v2->id]);
        $v2OrderB = Order::factory()->completed()->create(['service_id' => $service->id, 'service_version_id' => $v2->id]);
        Result::factory()->create(['request_id' => Request::factory()->create(['order_id' => $v2OrderA->id])->id, 'latency_ms' => 1000]);
        Result::factory()->create(['request_id' => Request::factory()->create(['order_id' => $v2OrderB->id])->id, 'latency_ms' => 2000]);

        $comparison = $this->analytics->versionComparison($service->id)->keyBy('version_no');

        $this->assertSame(0.5, $comparison[1]['completion_rate']);
        $this->assertSame(1.0, $comparison[2]['completion_rate']);
        $this->assertSame(8000, $comparison[1]['avg_latency_ms']);
        $this->assertSame(1500, $comparison[2]['avg_latency_ms']);
    }

    public function test_entry_mode_funnel_reports_completion_and_drop_off(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);

        Order::factory()->completed()->create(['service_id' => $service->id, 'service_version_id' => $version->id, 'entry_mode' => EntryMode::Wizard]);
        Order::factory()->completed()->create(['service_id' => $service->id, 'service_version_id' => $version->id, 'entry_mode' => EntryMode::Wizard]);
        Order::factory()->failed()->create(['service_id' => $service->id, 'service_version_id' => $version->id, 'entry_mode' => EntryMode::Wizard]);

        Order::factory()->completed()->create(['service_id' => $service->id, 'service_version_id' => $version->id, 'entry_mode' => EntryMode::Chat]);
        Order::factory()->failed()->create(['service_id' => $service->id, 'service_version_id' => $version->id, 'entry_mode' => EntryMode::Chat]);
        Order::factory()->failed()->create(['service_id' => $service->id, 'service_version_id' => $version->id, 'entry_mode' => EntryMode::Chat]);

        $funnel = $this->analytics->entryModeFunnel($service->id)->keyBy('entry_mode');

        $this->assertSame(3, $funnel['wizard']['orders']);
        $this->assertEqualsWithDelta(0.6667, $funnel['wizard']['completion_rate'], 0.001);
        $this->assertSame(3, $funnel['chat']['orders']);
        $this->assertEqualsWithDelta(0.3333, $funnel['chat']['completion_rate'], 0.001);
        $this->assertEqualsWithDelta(0.6667, $funnel['chat']['drop_off_rate'], 0.001);
    }
}
