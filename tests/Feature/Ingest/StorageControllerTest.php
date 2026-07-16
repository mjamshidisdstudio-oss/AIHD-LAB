<?php

namespace Tests\Feature\Ingest;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsIngestFixtures;
use Tests\TestCase;

/**
 * The storage API is called by the external/dev service, which has no user
 * session — it authenticates with the per-service webhook_signing_key, never
 * Sanctum. A real logged-in user's token must not work here; only the
 * service's own key does. media_id is opaque: unknown -> 404, wrong key ->
 * 401, oversized -> 413 — never a 500.
 */
class StorageControllerTest extends TestCase
{
    use BuildsIngestFixtures, RefreshDatabase;

    public function test_storage_endpoints_require_signing_key_and_reject_user_auth(): void
    {
        Storage::fake('media');
        ['service' => $service, 'order' => $order] = $this->ingestFixture(signingKey: 'storage-key');

        $file = File::factory()->create([
            'order_id' => $order->id,
            'disk' => 'media',
            'path' => 'results/'.$order->id.'/1.png',
            'mime' => 'image/png',
        ]);
        Storage::disk('media')->put($file->path, 'PNG-BYTES');

        // A real, valid Sanctum user token must NOT work here.
        $userToken = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($userToken)->getJson("/api/storage/{$file->id}")->assertUnauthorized();
        $this->withToken($userToken)
            ->post('/api/storage', ['order_id' => $order->id, 'file' => UploadedFile::fake()->image('x.png')])
            ->assertUnauthorized();

        // No token at all.
        $this->getJson("/api/storage/{$file->id}")->assertUnauthorized();

        // The service's own signing key works.
        $this->withToken('storage-key')->get("/api/storage/{$file->id}")->assertOk();

        $upload = $this->withToken('storage-key')->post('/api/storage', [
            'order_id' => $order->id,
            'file' => UploadedFile::fake()->image('result.png'),
        ])->assertCreated();

        $mediaId = $upload->json('media_id');
        $this->assertIsString($mediaId);
        // media_id is opaque — no disk path or bucket ever appears in the response.
        $upload->assertJsonMissingPath('disk');
        $upload->assertJsonMissingPath('path');
    }

    public function test_unknown_media_id_is_404_not_500(): void
    {
        ['service' => $service] = $this->ingestFixture(signingKey: 'storage-key');

        $this->withToken('storage-key')
            ->getJson('/api/storage/'.fake()->uuid())
            ->assertNotFound();
    }

    public function test_wrong_signing_key_is_401(): void
    {
        ['order' => $order] = $this->ingestFixture(signingKey: 'the-real-key');

        $file = File::factory()->create(['order_id' => $order->id, 'disk' => 'media', 'path' => 'results/x.png']);

        $this->withToken('the-wrong-key')->getJson("/api/storage/{$file->id}")->assertUnauthorized();
    }

    public function test_oversized_upload_is_413(): void
    {
        ['order' => $order] = $this->ingestFixture(signingKey: 'storage-key');

        $tooLarge = UploadedFile::fake()->create('huge.bin', 11 * 1024); // 11 MiB > 10 MiB ceiling

        $this->withToken('storage-key')
            ->post('/api/storage', ['order_id' => $order->id, 'file' => $tooLarge])
            ->assertStatus(413);
    }
}
