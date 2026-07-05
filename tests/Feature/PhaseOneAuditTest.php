<?php

namespace Tests\Feature;

use App\Enums\ServiceInputType;
use App\Models\Order;
use App\Models\OrderInput;
use App\Models\Request;
use App\Models\Result;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use App\Models\ServiceVote;
use Database\Seeders\SeasonalViewsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase-1 audit trail. Each `test_*_rejects_*` / nullable test is a "Never
 * Again" regression guard for an item the audit flagged WRONG; the remaining
 * tests lock the Phase-1 baseline.
 */
class PhaseOneAuditTest extends TestCase
{
    use RefreshDatabase;

    // ---- Never Again: repaired WRONG items ---------------------------------

    /**
     * Repair (a): the order_inputs CHECK must reject a value-bearing answer
     * that leaves BOTH scalar columns null (previously only "both set" was
     * rejected). Fails against the old CHECK (value_fill_count <= 1).
     */
    public function test_order_input_rejects_both_value_columns_null(): void
    {
        $input = ServiceInput::factory()->ofType(ServiceInputType::Text)->create();
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        OrderInput::query()->create([
            'order_id' => $order->id,
            'input_id' => $input->id,
            'value_text' => null,
            'value_bool' => null,
        ]);
    }

    /**
     * The mirror case that the old CHECK already caught — kept so the "exactly
     * one" guard is pinned from both directions.
     */
    public function test_order_input_rejects_both_value_columns_set(): void
    {
        $input = ServiceInput::factory()->ofType(ServiceInputType::Text)->create();
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        OrderInput::query()->create([
            'order_id' => $order->id,
            'input_id' => $input->id,
            'value_text' => 'hello',
            'value_bool' => true,
        ]);
    }

    /**
     * A value-bearing answer with exactly one column populated is accepted.
     */
    public function test_order_input_accepts_exactly_one_value_for_value_bearing_input(): void
    {
        $input = ServiceInput::factory()->ofType(ServiceInputType::Text)->create();
        $order = Order::factory()->create();

        $answer = OrderInput::query()->create([
            'order_id' => $order->id,
            'input_id' => $input->id,
            'value_text' => 'blue',
            'value_bool' => null,
        ]);

        $this->assertSame(1, $answer->fresh()->value_fill_count);
        $this->assertTrue($answer->fresh()->expects_scalar);
    }

    /**
     * A non-value-bearing input (its answer lives in options/files) carries no
     * scalar value and is accepted with both columns null.
     */
    public function test_order_input_accepts_no_value_for_non_value_bearing_input(): void
    {
        $input = ServiceInput::factory()->ofType(ServiceInputType::Select)->create();
        $order = Order::factory()->create();

        $answer = OrderInput::query()->create([
            'order_id' => $order->id,
            'input_id' => $input->id,
            'value_text' => null,
            'value_bool' => null,
        ]);

        $this->assertSame(0, $answer->fresh()->value_fill_count);
        $this->assertFalse($answer->fresh()->expects_scalar);
    }

    /**
     * Repair (b): services.avg_latency_ms must be nullable (unknown until the
     * first completed order). Fails against the old NOT NULL DEFAULT 0 column.
     */
    public function test_services_avg_latency_ms_is_nullable(): void
    {
        $service = Service::factory()->create(['avg_latency_ms' => null]);

        $this->assertNull($service->fresh()->avg_latency_ms);
    }

    // ---- Baseline locks -----------------------------------------------------

    public function test_migrate_fresh_seed_runs_clean(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, Service::where('slug', 'season-gen')->count());
    }

    public function test_seasonal_views_service_loads_with_all_relations_eager(): void
    {
        $this->seed(SeasonalViewsSeeder::class);

        $service = Service::where('slug', 'season-gen')
            ->with([
                'currentVersion.inputs.options.parentOptions',
                'currentVersion.inputs.dependsOnInput',
                'currentVersion.outputs',
                'currentVersion.waitingTexts',
            ])
            ->firstOrFail();

        $version = $service->currentVersion;
        $this->assertTrue($service->relationLoaded('currentVersion'));
        $this->assertTrue($version->relationLoaded('inputs'));
        $this->assertTrue($version->relationLoaded('outputs'));

        // All four image outputs load.
        $this->assertCount(4, $version->outputs);

        // The room_type -> style option_dependency loads eagerly.
        $inputs = $version->inputs->keyBy('slug');
        $style = $inputs['style'];
        $this->assertSame($inputs['room_type']->id, $style->depends_on_input_id);
        $roomTypeOptionIds = $inputs['room_type']->options->pluck('id')->all();
        foreach ($style->options as $option) {
            $this->assertTrue($option->relationLoaded('parentOptions'));
            $this->assertCount(1, $option->parentOptions);
            $this->assertContains($option->parentOptions->first()->id, $roomTypeOptionIds);
        }
    }

    public function test_duplicate_result_number_is_rejected(): void
    {
        $request = Request::factory()->create();
        Result::factory()->create(['request_id' => $request->id, 'result_number' => 1]);

        $this->expectException(QueryException::class);

        Result::factory()->create(['request_id' => $request->id, 'result_number' => 1]);
    }

    /** Alias spelled the way the audit named it. */
    public function test_results_reject_duplicate_request_result_number(): void
    {
        $request = Request::factory()->create();
        Result::factory()->create(['request_id' => $request->id, 'result_number' => 2]);

        $this->expectException(QueryException::class);

        Result::factory()->create(['request_id' => $request->id, 'result_number' => 2]);
    }

    public function test_duplicate_vote_per_user_is_rejected(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);

        ServiceVote::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'user_ref' => 'voter-1',
        ]);

        $this->expectException(QueryException::class);

        ServiceVote::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'user_ref' => 'voter-1',
        ]);
    }

    public function test_order_version_must_belong_to_service(): void
    {
        $serviceA = Service::factory()->create();
        $versionOfA = ServiceVersion::factory()->create(['service_id' => $serviceA->id]);
        $serviceB = Service::factory()->create();

        $this->expectException(QueryException::class);

        Order::factory()->create([
            'service_id' => $serviceB->id,
            'service_version_id' => $versionOfA->id,
        ]);
    }

    public function test_service_secret_is_stored_hashed(): void
    {
        $service = Service::factory()->create(['service_secret' => 'plain-text-secret']);

        $stored = $service->fresh()->getAttributes()['service_secret'];
        $this->assertNotSame('plain-text-secret', $stored);
        $this->assertTrue(password_verify('plain-text-secret', $stored));
    }
}
