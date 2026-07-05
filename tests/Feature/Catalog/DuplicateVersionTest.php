<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\DuplicateVersion;
use App\Actions\Catalog\PublishVersion;
use App\Enums\ServiceInputType;
use App\Exceptions\Catalog\VersionNotEditableException;
use App\Models\OptionDependency;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\ServiceWaitingText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The heart of Phase 2. Duplicating a version must produce a NEW draft whose
 * every internal foreign key points inside the new version — nothing may leak
 * back to the source version's ids. This is the "Never Again" guard against a
 * naive copy that reuses source ids for depends_on_input_id, option→input, or
 * the option_dependencies parent/child links.
 */
class DuplicateVersionTest extends TestCase
{
    use RefreshDatabase;

    private function buildSourceVersion(): ServiceVersion
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
        ]);

        $roomType = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
            'depends_on_input_id' => null,
        ]);
        // style depends on room_type at the input level.
        $style = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'style',
            'depends_on_input_id' => $roomType->id,
            'depends_on_value' => 'any',
        ]);

        $bedroom = ServiceInputOption::factory()->create(['input_id' => $roomType->id, 'slug' => 'bedroom']);
        $living = ServiceInputOption::factory()->create(['input_id' => $roomType->id, 'slug' => 'living']);
        $cozy = ServiceInputOption::factory()->create(['input_id' => $style->id, 'slug' => 'cozy']);
        $modern = ServiceInputOption::factory()->create(['input_id' => $style->id, 'slug' => 'modern']);

        // style options gated on room_type options (cross-input, same version).
        OptionDependency::factory()->create(['option_id' => $cozy->id, 'parent_option_id' => $bedroom->id]);
        OptionDependency::factory()->create(['option_id' => $modern->id, 'parent_option_id' => $living->id]);

        ServiceOutput::factory()->create(['service_version_id' => $version->id, 'result_number' => 1]);
        ServiceOutput::factory()->create(['service_version_id' => $version->id, 'result_number' => 2]);
        ServiceWaitingText::factory()->create(['service_version_id' => $version->id, 'sort_order' => 1]);

        return $version->fresh();
    }

    public function test_duplicated_version_remaps_all_foreign_keys_into_new_version(): void
    {
        $source = $this->buildSourceVersion();

        $new = app(DuplicateVersion::class)->handle($source);

        // New version basics: a fresh draft with the next version number.
        $this->assertNotSame($source->id, $new->id);
        $this->assertTrue($new->isDraft());
        $this->assertNull($new->published_at);
        $this->assertSame($source->service_id, $new->service_id);
        $this->assertSame($source->version_no + 1, $new->version_no);

        // Source-side id sets (nothing in the new version may reference these).
        $sourceInputIds = $source->inputs()->pluck('id')->all();
        $sourceOptionIds = ServiceInputOption::whereIn('input_id', $sourceInputIds)->pluck('id')->all();

        $newInputs = $new->inputs()->get();
        $newInputIds = $newInputs->pluck('id')->all();
        $newOptions = ServiceInputOption::whereIn('input_id', $newInputIds)->get();
        $newOptionIds = $newOptions->pluck('id')->all();

        // Same shape as the source.
        $this->assertCount(2, $newInputs);
        $this->assertCount(4, $newOptions);

        // (1) every new input belongs to the new version and is a fresh row.
        foreach ($newInputs as $input) {
            $this->assertSame($new->id, $input->service_version_id);
            $this->assertNotContains($input->id, $sourceInputIds);
        }

        // (2) depends_on_input_id is remapped to the NEW room_type input.
        $newRoomType = $newInputs->firstWhere('slug', 'room_type');
        $newStyle = $newInputs->firstWhere('slug', 'style');
        $this->assertNotNull($newStyle->depends_on_input_id);
        $this->assertSame($newRoomType->id, $newStyle->depends_on_input_id);
        $this->assertContains($newStyle->depends_on_input_id, $newInputIds);
        $this->assertNotContains($newStyle->depends_on_input_id, $sourceInputIds);
        // scalar fields still copied.
        $this->assertSame('any', $newStyle->depends_on_value);

        // (3) every new option points at a NEW input, and is a fresh row.
        foreach ($newOptions as $option) {
            $this->assertContains($option->input_id, $newInputIds);
            $this->assertNotContains($option->id, $sourceOptionIds);
        }

        // (4) every new option_dependency has BOTH ends remapped into the new
        //     version's options — parent and child alike.
        $newDeps = OptionDependency::whereIn('option_id', $newOptionIds)->get();
        $this->assertCount(2, $newDeps);
        foreach ($newDeps as $dep) {
            $this->assertContains($dep->option_id, $newOptionIds);
            $this->assertContains($dep->parent_option_id, $newOptionIds);
            $this->assertNotContains($dep->parent_option_id, $sourceOptionIds);
        }

        // (5) no dependency in the new version leaks a source option as parent.
        $leaked = OptionDependency::whereIn('option_id', $newOptionIds)
            ->whereIn('parent_option_id', $sourceOptionIds)
            ->count();
        $this->assertSame(0, $leaked);

        // (6) outputs and waiting texts copied.
        $this->assertSame(2, $new->outputs()->count());
        $this->assertSame(1, $new->waitingTexts()->count());

        // (7) the source version is left completely untouched.
        $this->assertCount(2, $source->inputs()->get());
        $this->assertSame(4, ServiceInputOption::whereIn('input_id', $sourceInputIds)->count());
        $this->assertSame(2, OptionDependency::whereIn('option_id', $sourceOptionIds)->count());
        $sourceStyle = $source->inputs()->where('slug', 'style')->first();
        $this->assertContains($sourceStyle->depends_on_input_id, $sourceInputIds);
    }

    /**
     * The real reason duplicate exists: a published version is frozen, so you
     * duplicate it to get an editable draft. The copy must be editable and the
     * published source must stay frozen.
     */
    public function test_duplicating_a_published_version_yields_an_editable_draft(): void
    {
        $source = $this->buildSourceVersion();
        app(PublishVersion::class)->handle($source);
        $this->assertTrue($source->refresh()->isPublished());

        $draft = app(DuplicateVersion::class)->handle($source);

        $this->assertTrue($draft->isDraft());

        // The draft is editable: adding a new input succeeds.
        $newInput = ServiceInput::factory()->ofType(ServiceInputType::Boolean)->create([
            'service_version_id' => $draft->id,
            'slug' => 'hd',
        ]);
        $this->assertDatabaseHas('service_inputs', ['id' => $newInput->id]);

        // The published source stays frozen.
        $this->expectException(VersionNotEditableException::class);
        ServiceInput::factory()->create(['service_version_id' => $source->id]);
    }
}
