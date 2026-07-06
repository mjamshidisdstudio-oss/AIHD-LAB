<?php

namespace Tests\Feature\Catalog;

use App\Enums\ServiceInputType;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Input visibility (depends_on_input_id) and option gating (option_dependencies)
 * both form directed graphs. A cycle would make the form impossible to render —
 * every node waiting on another forever. The admin API must reject any edit that
 * closes a loop, and any edge that reaches across version boundaries.
 *
 * "Never Again": a cycle slipped in once and hung the form renderer. No edge may
 * point at a node that already (transitively) depends on the source.
 */
class DependencyCycleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function draftVersion(): ServiceVersion
    {
        $service = Service::factory()->create();

        return ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
        ]);
    }

    // ---- input dependency graph --------------------------------------------

    public function test_an_input_cannot_depend_on_itself(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();
        $input = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
        ]);

        $this->patchJson("/api/admin/inputs/{$input->id}", [
            'depends_on_input_id' => $input->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['depends_on_input_id']);
    }

    public function test_updating_an_input_to_depend_on_its_dependent_forms_a_cycle_and_is_rejected(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();

        $roomType = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
            'depends_on_input_id' => null,
        ]);
        // style depends on room_type.
        $style = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'style',
            'depends_on_input_id' => $roomType->id,
        ]);

        // Now try to make room_type depend on style -> room_type -> style -> room_type.
        $this->patchJson("/api/admin/inputs/{$roomType->id}", [
            'depends_on_input_id' => $style->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['depends_on_input_id']);

        $this->assertNull($roomType->refresh()->depends_on_input_id);
    }

    public function test_a_non_cyclic_input_dependency_update_is_accepted(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();

        $roomType = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
        ]);
        $style = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'style',
            'depends_on_input_id' => null,
        ]);

        $this->patchJson("/api/admin/inputs/{$style->id}", [
            'depends_on_input_id' => $roomType->id,
        ])->assertOk();

        $this->assertSame($roomType->id, $style->refresh()->depends_on_input_id);
    }

    // ---- option dependency graph -------------------------------------------

    /**
     * @return array{version: ServiceVersion, a: ServiceInputOption, b: ServiceInputOption}
     */
    private function twoOptions(): array
    {
        $version = $this->draftVersion();
        $inputA = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'a',
        ]);
        $inputB = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'b',
        ]);

        return [
            'version' => $version,
            'a' => ServiceInputOption::factory()->create(['input_id' => $inputA->id, 'slug' => 'oa']),
            'b' => ServiceInputOption::factory()->create(['input_id' => $inputB->id, 'slug' => 'ob']),
        ];
    }

    public function test_a_valid_option_dependency_is_created(): void
    {
        $this->actingAsAdmin();
        ['version' => $version, 'a' => $a, 'b' => $b] = $this->twoOptions();

        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $b->id,
            'parent_option_id' => $a->id,
        ])->assertCreated();

        $this->assertDatabaseHas('option_dependencies', [
            'option_id' => $b->id, 'parent_option_id' => $a->id,
        ]);
    }

    public function test_an_option_cannot_depend_on_itself(): void
    {
        $this->actingAsAdmin();
        ['version' => $version, 'a' => $a] = $this->twoOptions();

        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $a->id,
            'parent_option_id' => $a->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_option_id']);
    }

    public function test_an_option_dependency_that_closes_a_cycle_is_rejected(): void
    {
        $this->actingAsAdmin();
        ['version' => $version, 'a' => $a, 'b' => $b] = $this->twoOptions();

        // b depends on a.
        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $b->id,
            'parent_option_id' => $a->id,
        ])->assertCreated();

        // a depending on b would close a -> b -> a.
        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $a->id,
            'parent_option_id' => $b->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_option_id']);

        $this->assertDatabaseMissing('option_dependencies', [
            'option_id' => $a->id, 'parent_option_id' => $b->id,
        ]);
    }

    public function test_an_option_dependency_across_versions_is_rejected(): void
    {
        $this->actingAsAdmin();
        ['version' => $version, 'a' => $a] = $this->twoOptions();

        // An option belonging to a DIFFERENT version.
        $otherVersion = $this->draftVersion();
        $otherInput = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $otherVersion->id, 'slug' => 'x',
        ]);
        $foreign = ServiceInputOption::factory()->create(['input_id' => $otherInput->id, 'slug' => 'ox']);

        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $a->id,
            'parent_option_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_option_id']);
    }

    public function test_a_duplicate_option_dependency_is_rejected(): void
    {
        $this->actingAsAdmin();
        ['version' => $version, 'a' => $a, 'b' => $b] = $this->twoOptions();

        $payload = ['option_id' => $b->id, 'parent_option_id' => $a->id];
        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", $payload)->assertCreated();
        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['parent_option_id']);
    }
}
