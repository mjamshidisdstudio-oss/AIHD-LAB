<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishVersion;
use App\Enums\ServiceInputType;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    // ---- authentication & authorization ------------------------------------

    public function test_guest_cannot_access_the_admin_api(): void
    {
        $this->getJson('/api/admin/services')->assertUnauthorized();
        $this->postJson('/api/admin/services', [])->assertUnauthorized();
    }

    public function test_a_non_admin_user_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/admin/services')->assertForbidden();
    }

    // ---- services -----------------------------------------------------------

    public function test_admin_can_create_a_service_with_an_initial_draft_version(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/services', [
            'slug' => 'my-service',
            'name' => 'My Service',
            'kind' => 'internal',
            'category' => 'interior',
        ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'my-service');
        $this->assertDatabaseHas('services', ['slug' => 'my-service']);

        $service = Service::where('slug', 'my-service')->firstOrFail();
        $this->assertSame(1, $service->versions()->count());
        $this->assertTrue($service->versions()->first()->isDraft());
        // The secret is never exposed by the API.
        $response->assertJsonMissingPath('data.service_secret');
    }

    public function test_create_service_requires_valid_input(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/services', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug', 'name', 'kind', 'category']);
    }

    public function test_create_service_rejects_a_duplicate_slug(): void
    {
        $this->actingAsAdmin();
        Service::factory()->create(['slug' => 'taken']);

        $this->postJson('/api/admin/services', [
            'slug' => 'taken',
            'name' => 'X',
            'kind' => 'internal',
            'category' => 'c',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_external_service_requires_an_external_url(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/services', [
            'slug' => 'ext',
            'name' => 'Ext',
            'kind' => 'external',
            'category' => 'c',
        ])->assertStatus(422)->assertJsonValidationErrors(['external_url']);
    }

    // ---- version lifecycle --------------------------------------------------

    public function test_admin_can_add_an_input_and_option_to_a_draft_version(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        $inputId = $this->postJson("/api/admin/versions/{$version->id}/inputs", [
            'slug' => 'room_type',
            'title' => 'Room Type',
            'type' => 'select',
            'required' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/inputs/{$inputId}/options", [
            'slug' => 'bedroom',
            'label' => 'Bedroom',
        ])->assertCreated();

        $this->assertDatabaseHas('service_input_options', ['slug' => 'bedroom', 'input_id' => $inputId]);
    }

    public function test_admin_can_duplicate_a_version_via_the_api(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
        ]);

        $response = $this->postJson("/api/admin/versions/{$version->id}/duplicate")->assertCreated();

        $newId = $response->json('data.id');
        $this->assertNotSame($version->id, $newId);
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame(2, $response->json('data.version_no'));
    }

    public function test_admin_can_publish_and_retire_a_version_via_the_api(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        $this->postJson("/api/admin/versions/{$version->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->assertSame($version->id, $service->refresh()->current_version_id);

        $this->postJson("/api/admin/versions/{$version->id}/retire")
            ->assertOk()
            ->assertJsonPath('data.status', 'retired');
        $this->assertNull($service->refresh()->current_version_id);
    }

    public function test_publishing_via_the_api_retires_the_previous_version(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $v1 = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        $v2 = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 2]);

        $this->postJson("/api/admin/versions/{$v1->id}/publish")->assertOk();
        $this->postJson("/api/admin/versions/{$v2->id}/publish")->assertOk();

        $this->assertSame('retired', $v1->refresh()->status->value);
        $this->assertSame($v2->id, $service->refresh()->current_version_id);
    }

    // ---- editing guard via the API -----------------------------------------

    public function test_cannot_add_an_input_to_a_published_version_via_the_api(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        app(PublishVersion::class)->handle($version);

        $this->postJson("/api/admin/versions/{$version->id}/inputs", [
            'slug' => 'late',
            'title' => 'Late',
            'type' => 'text',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'draft'));
    }

    public function test_cannot_update_a_published_versions_input_via_the_api(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        $input = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
        ]);
        app(PublishVersion::class)->handle($version);

        $this->patchJson("/api/admin/inputs/{$input->id}", ['title' => 'changed'])
            ->assertStatus(422);

        $this->assertSame('room_type', $input->refresh()->slug);
    }

    public function test_cannot_publish_a_non_draft_version_via_the_api(): void
    {
        $this->actingAsAdmin();
        $version = ServiceVersion::factory()->retired()->create();

        $this->postJson("/api/admin/versions/{$version->id}/publish")->assertStatus(422);
    }
}
