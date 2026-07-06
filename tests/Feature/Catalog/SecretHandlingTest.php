<?php

namespace Tests\Feature\Catalog;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The service secret is pasted by an operator, never invented by us. It is
 * stored hashed, hidden from every API response, and the only thing an operator
 * ever sees back is a short, non-reversible fingerprint so they can tell "a
 * secret is set" and which one — without the value ever leaving the database.
 *
 * "Never Again": we once generated a random secret when none was pasted, which
 * silently minted a credential nobody knew. A service created without a secret
 * must have none.
 */
class SecretHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_creating_a_service_without_a_secret_does_not_generate_one(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/services', [
            'slug' => 'no-secret',
            'name' => 'No Secret',
            'kind' => 'internal',
            'category' => 'interior',
        ])->assertCreated()
            ->assertJsonPath('data.has_secret', false)
            ->assertJsonPath('data.secret_preview', null);

        $service = Service::where('slug', 'no-secret')->firstOrFail();
        $this->assertNull($service->getAttributes()['service_secret']);
    }

    public function test_a_pasted_secret_is_stored_hashed_never_in_plaintext(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/services', [
            'slug' => 'with-secret',
            'name' => 'With Secret',
            'kind' => 'internal',
            'category' => 'interior',
            'service_secret' => 'paste-me-please',
        ])->assertCreated()->assertJsonPath('data.has_secret', true);

        $stored = Service::where('slug', 'with-secret')->firstOrFail()->getAttributes()['service_secret'];

        $this->assertNotSame('paste-me-please', $stored);
        $this->assertTrue(Hash::check('paste-me-please', $stored));
    }

    public function test_the_api_exposes_only_a_short_preview_never_the_hash_or_plaintext(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/services', [
            'slug' => 'preview-only',
            'name' => 'Preview Only',
            'kind' => 'internal',
            'category' => 'interior',
            'service_secret' => 'top-secret',
        ])->assertCreated();

        $response->assertJsonMissingPath('data.service_secret');

        $preview = $response->json('data.secret_preview');
        $stored = Service::where('slug', 'preview-only')->firstOrFail()->getAttributes()['service_secret'];

        $this->assertIsString($preview);
        // A short fingerprint: not the plaintext, not the stored hash.
        $this->assertNotSame('top-secret', $preview);
        $this->assertNotSame($stored, $preview);
        $this->assertLessThanOrEqual(16, strlen($preview));
    }

    public function test_a_secret_can_be_rotated_via_update_and_is_rehashed(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create(['service_secret' => 'old-secret']);
        $before = $service->getAttributes()['service_secret'];

        $this->patchJson("/api/admin/services/{$service->id}", [
            'service_secret' => 'brand-new-secret',
        ])->assertOk()->assertJsonPath('data.has_secret', true);

        $after = $service->refresh()->getAttributes()['service_secret'];

        $this->assertNotSame($before, $after);
        $this->assertTrue(Hash::check('brand-new-secret', $after));
    }
}
