<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Phase-2 exit criterion, driven entirely through the HTTP API: create a
 * service, publish v1, duplicate it to an editable v2, edit v2, publish v2, and
 * confirm v1 is retired while the service now points at v2. Nothing here reaches
 * around the API into the models.
 */
class CatalogLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_full_create_duplicate_edit_publish_lifecycle_via_the_api(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // 1. Create the service (mints an initial draft v1).
        $create = $this->postJson('/api/admin/services', [
            'slug' => 'seasonal-views',
            'name' => 'Seasonal Views',
            'kind' => 'internal',
            'category' => 'interior',
            'service_secret' => 'pasted-by-operator',
        ])->assertCreated();

        $serviceId = $create->json('data.id');
        $this->assertTrue($create->json('data.has_secret'));
        $this->assertCount(1, $create->json('data.versions'));
        $v1 = $create->json('data.versions.0.id');

        // Give v1 some content so the duplicate has foreign keys to remap.
        $inputId = $this->postJson("/api/admin/versions/{$v1}/inputs", [
            'slug' => 'room_type',
            'title' => 'Room Type',
            'type' => 'select',
            'required' => true,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/inputs/{$inputId}/options", [
            'slug' => 'bedroom', 'label' => 'Bedroom',
        ])->assertCreated();
        $this->postJson("/api/admin/versions/{$v1}/outputs", [
            'result_number' => 1, 'type' => 'image',
        ])->assertCreated();

        // 2. Publish v1 — the service now serves it.
        $this->postJson("/api/admin/versions/{$v1}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame($v1, $this->getJson("/api/admin/services/{$serviceId}")->json('data.current_version_id'));

        // 3. Duplicate v1 -> v2 (a fresh draft with remapped content).
        $duplicate = $this->postJson("/api/admin/versions/{$v1}/duplicate")->assertCreated();
        $v2 = $duplicate->json('data.id');
        $this->assertNotSame($v1, $v2);
        $this->assertSame('draft', $duplicate->json('data.status'));
        $this->assertSame(2, $duplicate->json('data.version_no'));

        // 4. Edit v2 (only possible because it is a draft).
        $v2Show = $this->getJson("/api/admin/versions/{$v2}")->assertOk();
        $v2InputId = $v2Show->json('data.inputs.0.id');
        $this->assertNotSame($inputId, $v2InputId); // remapped to the new version
        $this->patchJson("/api/admin/inputs/{$v2InputId}", ['title' => 'Room'])
            ->assertOk()->assertJsonPath('data.title', 'Room');
        $this->postJson("/api/admin/versions/{$v2}/waiting-texts", [
            'text' => 'Rendering your seasonal look…',
        ])->assertCreated();

        // 5. Publish v2 — v1 must retire first, so there is never a moment with
        //    two published versions.
        $this->postJson("/api/admin/versions/{$v2}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published');

        // 6. Exit assertions, all read back through the API.
        $this->getJson("/api/admin/versions/{$v1}")
            ->assertOk()->assertJsonPath('data.status', 'retired');

        $service = $this->getJson("/api/admin/services/{$serviceId}")->assertOk();
        $this->assertSame($v2, $service->json('data.current_version_id'));

        // The original v1 content is untouched by the edits to v2.
        $this->getJson("/api/admin/versions/{$v1}")
            ->assertJsonPath('data.inputs.0.title', 'Room Type');
    }
}
