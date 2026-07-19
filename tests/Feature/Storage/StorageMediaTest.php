<?php

namespace Tests\Feature\Storage;

use App\Actions\Catalog\PublishVersion;
use App\Actions\Storage\StoreMedia;
use App\Contracts\CoinService;
use App\Enums\FileKind;
use App\Enums\MediaType;
use App\Enums\ServiceInputType;
use App\Jobs\DispatchRequest;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use App\Services\Coins\MockCoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * We host our own storage -- not a core-team dependency. Every media flow
 * (our own input uploads, an external service's results) goes through the
 * same StoreMedia action StorageController::store() calls for
 * POST /api/storage; nothing writes to a disk directly. This also proves the
 * disk itself is config-driven (the "media" disk's env('MEDIA_DRIVER')) --
 * swappable to a production disk with no code change.
 */
class StorageMediaTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedServiceWithImageInput(): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => 0,
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Image)->required()->create([
            'service_version_id' => $version->id,
            'slug' => 'room_photo',
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    /**
     * Never Again: our own input upload must go through the exact same
     * StoreMedia action the external-facing POST /api/storage endpoint uses
     * -- not a separate, direct-to-disk write. A spy proves the shared code
     * path was actually invoked (a black-box check on the resulting File row
     * can't tell the two implementations apart, since both would produce an
     * identical shape).
     */
    public function test_our_own_input_upload_goes_through_storage_api_not_direct_disk(): void
    {
        Storage::fake('media');
        Queue::fake([DispatchRequest::class]);
        $this->app->instance(CoinService::class, new MockCoinService);

        $service = $this->publishedServiceWithImageInput();
        $headers = $this->coreUserHeaders('user-1');

        // Spies on the REAL instance -- calls still genuinely write the file
        // and create the File row (SubmitOrder needs the real return value
        // to keep going), while recording that the shared action was hit.
        $spy = Mockery::spy(new StoreMedia);
        $this->app->instance(StoreMedia::class, $spy);

        $orderId = $this->withHeaders($headers)->post('/api/orders', [
            'service_id' => $service->id,
            'answers' => [],
            'files' => ['room_photo' => UploadedFile::fake()->image('room.png')],
        ])->assertStatus(202)->json('data.id');

        $spy->shouldHaveReceived('handle')
            ->once()
            ->withArgs(fn (Order $order, $file, FileKind $kind) => $order->id === $orderId && $kind === FileKind::Input);
    }

    /**
     * Never Again: the storage write/read path only ever references the
     * "media" disk by name -- never a driver or root path directly -- so
     * repointing that one disk (local today, s3/r2 in production via
     * MEDIA_DRIVER) is a config change, not a code change. Proven here by
     * repointing "media" to a different root at runtime and confirming the
     * exact same StoreMedia/StorageController code still round-trips a file
     * correctly.
     */
    public function test_storage_disk_is_config_driven_swappable(): void
    {
        config(['filesystems.disks.media' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/alt-media-disk'),
        ]]);

        $service = Service::factory()->create();
        $order = Order::factory()->create(['service_id' => $service->id, 'service_version_id' => ServiceVersion::factory()->create(['service_id' => $service->id])->id]);

        $file = app(StoreMedia::class)->handle($order, UploadedFile::fake()->image('swap-test.png'), FileKind::Input, MediaType::Image);

        $this->assertSame('media', $file->disk);
        $this->assertTrue(Storage::disk('media')->exists($file->path));
        $this->assertStringContainsString('alt-media-disk', Storage::disk('media')->path($file->path));

        // The same disk name, read back through the real GET /storage/{media_id}
        // endpoint -- no code anywhere needed to know the disk had moved.
        $this->withToken($service->webhook_signing_key)
            ->get("/api/storage/{$file->id}")
            ->assertOk();
    }
}
