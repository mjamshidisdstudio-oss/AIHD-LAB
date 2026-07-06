<?php

namespace Tests\Feature\Catalog;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A service carries TWO independent secrets, each doing exactly one job:
 *
 *   - service_secret       bcrypt-hashed, verify-a-pasted-value only, NOT
 *                          retrievable (see SecretHandlingTest).
 *   - webhook_signing_key  encrypted-at-rest, RETRIEVABLE, because webhook HMAC
 *                          (Phase 4) and storage key auth (Phase 5) must recompute
 *                          against the raw value — something bcrypt can never give
 *                          back.
 *
 * "Never Again": we once tried to make one hashed column serve both roles, which
 * made HMAC impossible. The signing key must round-trip to its pasted plaintext,
 * must never be generated, and must never be forced to share a column with the
 * hashed secret.
 */
class WebhookSigningKeyTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_the_signing_key_is_retrievable_unlike_the_hashed_secret(): void
    {
        $service = Service::factory()->create([
            'service_secret' => 'verify-me',
            'webhook_signing_key' => 'recompute-me',
        ])->fresh();

        // The signing key round-trips to the pasted plaintext — HMAC needs this.
        $this->assertSame('recompute-me', $service->webhook_signing_key);

        // ...but it is encrypted at rest, so the stored bytes are neither the
        // plaintext nor a bcrypt hash.
        $storedKey = $service->getAttributes()['webhook_signing_key'];
        $this->assertNotSame('recompute-me', $storedKey);
        $this->assertStringStartsNotWith('$2y$', $storedKey);

        // The hashed secret, by contrast, can NEVER be recovered — proving the two
        // columns are genuinely different mechanisms, not one doing both jobs.
        $this->assertNotSame('verify-me', $service->getAttributes()['service_secret']);
    }

    public function test_the_signing_key_is_never_generated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/services', [
            'slug' => 'no-key',
            'name' => 'No Key',
            'kind' => 'internal',
            'category' => 'interior',
        ])->assertCreated()->assertJsonPath('data.has_webhook_signing_key', false);

        $service = Service::where('slug', 'no-key')->firstOrFail();
        $this->assertNull($service->getAttributes()['webhook_signing_key']);
    }

    public function test_the_two_secrets_are_independent(): void
    {
        // Only a signing key, no verify-secret.
        $onlyKey = Service::factory()->create([
            'service_secret' => null,
            'webhook_signing_key' => 'k',
        ])->fresh();
        $this->assertFalse($onlyKey->has_secret);
        $this->assertTrue($onlyKey->has_webhook_signing_key);
        $this->assertSame('k', $onlyKey->webhook_signing_key);

        // Only a verify-secret, no signing key.
        $onlySecret = Service::factory()->create([
            'service_secret' => 's',
            'webhook_signing_key' => null,
        ])->fresh();
        $this->assertTrue($onlySecret->has_secret);
        $this->assertFalse($onlySecret->has_webhook_signing_key);
        $this->assertNull($onlySecret->webhook_signing_key);
    }

    public function test_the_api_exposes_only_a_preview_never_the_raw_signing_key(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/services', [
            'slug' => 'with-key',
            'name' => 'With Key',
            'kind' => 'internal',
            'category' => 'interior',
            'webhook_signing_key' => 'super-signing-key',
        ])->assertCreated()->assertJsonPath('data.has_webhook_signing_key', true);

        $response->assertJsonMissingPath('data.webhook_signing_key');
        $preview = $response->json('data.webhook_signing_key_preview');
        $this->assertIsString($preview);
        $this->assertNotSame('super-signing-key', $preview);
        $this->assertLessThanOrEqual(16, strlen($preview));
    }

    public function test_the_signing_key_can_be_rotated_and_stays_retrievable(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create(['webhook_signing_key' => 'old-key']);

        $this->patchJson("/api/admin/services/{$service->id}", [
            'webhook_signing_key' => 'new-key',
        ])->assertOk()->assertJsonPath('data.has_webhook_signing_key', true);

        $this->assertSame('new-key', $service->refresh()->webhook_signing_key);
    }
}
