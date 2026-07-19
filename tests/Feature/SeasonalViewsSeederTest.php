<?php

namespace Tests\Feature;

use App\Enums\ServiceInputType;
use App\Enums\ServiceKind;
use App\Enums\ServiceOutputType;
use App\Enums\ServiceVersionStatus;
use App\Models\Service;
use Database\Seeders\SeasonalViewsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonalViewsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_seasonal_views_service_as_specified(): void
    {
        $this->seed(SeasonalViewsSeeder::class);

        $service = Service::where('slug', 'season-gen')->firstOrFail();

        $this->assertSame('Seasonal Views', $service->name);
        $this->assertSame(ServiceKind::Internal, $service->kind);
        $this->assertNotNull($service->current_version_id);

        $version = $service->currentVersion;
        $this->assertSame(ServiceVersionStatus::Published, $version->status);
        $this->assertSame(0, $version->coin_cost);
        $this->assertSame(3, $version->regenerate_limit);
        $this->assertSame(120, $version->response_timeout_s);
        $this->assertSame(30, $version->get_interval_s);
        $this->assertSame(10, $version->max_get_attempts);

        // Four image outputs, three waiting texts.
        $this->assertCount(4, $version->outputs);
        $version->outputs->each(fn ($o) => $this->assertSame(ServiceOutputType::Image, $o->type));
        $this->assertCount(3, $version->waitingTexts);

        // Inputs and their types.
        $inputs = $version->inputs->keyBy('slug');
        $this->assertCount(4, $inputs);
        $this->assertSame(ServiceInputType::Image, $inputs['room_photo']->type);
        $this->assertTrue($inputs['room_photo']->required);
        $this->assertSame(ServiceInputType::Select, $inputs['room_type']->type);
        $this->assertSame(ServiceInputType::Select, $inputs['style']->type);
        $this->assertSame(ServiceInputType::Boolean, $inputs['hd']->type);

        // room_type offers bedroom / living / kitchen.
        $this->assertEqualsCanonicalizing(
            ['bedroom', 'living', 'kitchen'],
            $inputs['room_type']->options->pluck('slug')->all(),
        );

        // style is gated on room_type, and every style option is gated (via
        // option_dependencies) on exactly one room_type option.
        $this->assertSame($inputs['room_type']->id, $inputs['style']->depends_on_input_id);
        $roomTypeOptionIds = $inputs['room_type']->options->pluck('id')->all();

        $style = $inputs['style']->load('options.parentOptions');
        $this->assertCount(6, $style->options);
        foreach ($style->options as $option) {
            $this->assertCount(1, $option->parentOptions);
            $this->assertContains($option->parentOptions->first()->id, $roomTypeOptionIds);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SeasonalViewsSeeder::class);
        $this->seed(SeasonalViewsSeeder::class);

        $this->assertSame(1, Service::where('slug', 'season-gen')->count());
        $version = Service::where('slug', 'season-gen')->firstOrFail()->currentVersion;
        $this->assertCount(4, $version->inputs);
        $this->assertCount(4, $version->outputs);
        $this->assertCount(3, $version->waitingTexts);
    }
}
