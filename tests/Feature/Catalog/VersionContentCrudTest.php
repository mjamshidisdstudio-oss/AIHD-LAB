<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishVersion;
use App\Enums\ServiceInputType;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD for the remaining version-content resources — outputs, waiting texts and
 * option dependencies — through the admin API, plus the model-layer guard that
 * freezes them once the version leaves draft.
 */
class VersionContentCrudTest extends TestCase
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

    // ---- outputs ------------------------------------------------------------

    public function test_admin_can_crud_a_service_output(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();

        $id = $this->postJson("/api/admin/versions/{$version->id}/outputs", [
            'result_number' => 1,
            'type' => 'image',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/outputs/{$id}", ['type' => 'video'])
            ->assertOk()->assertJsonPath('data.type', 'video');

        $this->deleteJson("/api/admin/outputs/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('service_outputs', ['id' => $id]);
    }

    public function test_output_result_number_is_unique_per_version(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();
        ServiceOutput::factory()->create(['service_version_id' => $version->id, 'result_number' => 1]);

        $this->postJson("/api/admin/versions/{$version->id}/outputs", [
            'result_number' => 1,
            'type' => 'image',
        ])->assertStatus(422)->assertJsonValidationErrors(['result_number']);
    }

    public function test_cannot_add_an_output_to_a_published_version(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();
        app(PublishVersion::class)->handle($version);

        $this->postJson("/api/admin/versions/{$version->id}/outputs", [
            'result_number' => 1,
            'type' => 'image',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'draft'));
    }

    // ---- waiting texts ------------------------------------------------------

    public function test_admin_can_crud_a_waiting_text(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();

        $id = $this->postJson("/api/admin/versions/{$version->id}/waiting-texts", [
            'text' => 'Hang tight…',
            'sort_order' => 1,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/waiting-texts/{$id}", ['text' => 'Almost there…'])
            ->assertOk()->assertJsonPath('data.text', 'Almost there…');

        $this->deleteJson("/api/admin/waiting-texts/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('service_waiting_texts', ['id' => $id]);
    }

    public function test_cannot_add_a_waiting_text_to_a_published_version(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();
        app(PublishVersion::class)->handle($version);

        $this->postJson("/api/admin/versions/{$version->id}/waiting-texts", [
            'text' => 'too late',
        ])->assertStatus(422);
    }

    // ---- option dependencies ------------------------------------------------

    public function test_admin_can_create_and_delete_an_option_dependency(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();
        $inputA = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'a',
        ]);
        $inputB = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'b',
        ]);
        $a = ServiceInputOption::factory()->create(['input_id' => $inputA->id, 'slug' => 'oa']);
        $b = ServiceInputOption::factory()->create(['input_id' => $inputB->id, 'slug' => 'ob']);

        $id = $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $b->id,
            'parent_option_id' => $a->id,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/option-dependencies/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('option_dependencies', ['id' => $id]);
    }

    public function test_cannot_add_an_option_dependency_to_a_published_version(): void
    {
        $this->actingAsAdmin();
        $version = $this->draftVersion();
        $inputA = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'a',
        ]);
        $inputB = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'b',
        ]);
        $a = ServiceInputOption::factory()->create(['input_id' => $inputA->id, 'slug' => 'oa']);
        $b = ServiceInputOption::factory()->create(['input_id' => $inputB->id, 'slug' => 'ob']);

        app(PublishVersion::class)->handle($version);

        $this->postJson("/api/admin/versions/{$version->id}/option-dependencies", [
            'option_id' => $b->id,
            'parent_option_id' => $a->id,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'draft'));
    }
}
