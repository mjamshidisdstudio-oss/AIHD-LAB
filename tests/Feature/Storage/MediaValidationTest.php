<?php

namespace Tests\Feature\Storage;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Enums\ServiceInputType;
use App\Enums\ServiceOutputType;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Services\Coins\MockCoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsCoreUser;
use Tests\Concerns\BuildsIngestFixtures;
use Tests\Concerns\GeneratesFakeMedia;
use Tests\TestCase;

/**
 * Phase L4: a config-driven, per-media-type format allow-list and size
 * ceiling, enforced by StoreMedia -- the one shared write path both our own
 * input uploads and an external service's result uploads go through, so
 * there is exactly one place this policy can ever be bypassed.
 */
class MediaValidationTest extends TestCase
{
    use ActsAsCoreUser, BuildsIngestFixtures, GeneratesFakeMedia, RefreshDatabase;

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
     * Never Again: an input upload whose real, content-sniffed mime isn't on
     * the image allow-list is rejected (422) -- before Phase L4 there was no
     * format allow-list at all on this path, only a flat size cap.
     */
    public function test_input_upload_rejects_disallowed_format(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = $this->publishedServiceWithImageInput();
        $headers = $this->coreUserHeaders('user-1');

        $notAnImage = UploadedFile::fake()->create('malware.exe', 10)->mimeType('application/x-msdownload');

        $this->withHeaders($headers)->post('/api/orders', [
            'service_id' => $service->id,
            'answers' => [],
            'files' => ['room_photo' => $notAnImage],
        ])->assertStatus(422);
    }

    /**
     * Never Again: an external service delivering a result whose real mime
     * doesn't match the output's DECLARED type (its version says image, the
     * upload is really a video) is rejected -- the exact "misbehaving
     * service" case this policy exists to catch.
     */
    public function test_result_upload_rejects_mime_not_matching_declared_output_type(): void
    {
        ['order' => $order, 'version' => $version] = $this->ingestFixture(signingKey: 'storage-key');
        ServiceOutput::where('service_version_id', $version->id)->update(['type' => ServiceOutputType::Video]);

        // A real, content-sniffable image -- but the declared output is video.
        $mismatched = UploadedFile::fake()->image('result.png');

        $this->withToken('storage-key')
            ->post('/api/storage', ['order_id' => $order->id, 'result_number' => 1, 'file' => $mismatched])
            ->assertStatus(422);
    }

    /**
     * Never Again: a video-type output accepts a file well over the OLD flat
     * 10 MiB ceiling (up to the video cap) -- proving limits are genuinely
     * per-type, not a leftover global number that would otherwise block
     * every real video upload.
     */
    public function test_video_output_accepts_large_file_under_video_cap(): void
    {
        ['order' => $order, 'version' => $version] = $this->ingestFixture(signingKey: 'storage-key');
        ServiceOutput::where('service_version_id', $version->id)->update(['type' => ServiceOutputType::Video]);

        $bigVideo = UploadedFile::fake()->create('clip.mp4', 50 * 1024)->mimeType('video/mp4'); // 50 MiB

        $this->withToken('storage-key')
            ->post('/api/storage', ['order_id' => $order->id, 'result_number' => 1, 'file' => $bigVideo])
            ->assertCreated();
    }

    /**
     * Never Again: the SAME file size that a video output accepts is
     * rejected for an image-type output -- proving the cap really is
     * per-type (an image output does not inherit video's much larger
     * ceiling). The message is asserted too, not just the status: a flat,
     * type-blind ceiling would also reject a 50 MiB file (coincidentally,
     * for an unrelated reason), so the status code alone can't tell the two
     * apart -- the per-type message ("image uploads are capped at...") can.
     */
    public function test_oversized_upload_rejected_per_type_cap(): void
    {
        ['order' => $order, 'version' => $version] = $this->ingestFixture(signingKey: 'storage-key');
        ServiceOutput::where('service_version_id', $version->id)->update(['type' => ServiceOutputType::Image]);

        $bigImage = UploadedFile::fake()->create('big.png', 50 * 1024)->mimeType('image/png'); // 50 MiB > 25 MiB image cap

        $this->withToken('storage-key')
            ->post('/api/storage', ['order_id' => $order->id, 'result_number' => 1, 'file' => $bigImage])
            ->assertStatus(413)
            ->assertJsonFragment(['message' => 'image uploads are capped at 26214400 bytes; this file is 52428800 bytes.']);
    }

    /**
     * Never Again: mime detection is genuine content-sniffing (Symfony's
     * fileinfo-backed UploadedFile::getMimeType()), not the filename
     * extension or a claimed Content-Type -- Laravel's own fake()/create()
     * helpers derive their reported mime from the extension, which would
     * never actually exercise this, so this constructs a REAL UploadedFile
     * around genuine ELF executable bytes named to look like a photo.
     */
    public function test_executable_disguised_upload_is_rejected_by_content_sniff(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = $this->publishedServiceWithImageInput();
        $headers = $this->coreUserHeaders('user-1');

        $elfBytes = "\x7fELF\x01\x01\x01\x00".str_repeat("\x00", 8)."\x02\x00\x03\x00".str_repeat("\x00", 100);
        $tmpPath = tempnam(sys_get_temp_dir(), 'evil-');
        file_put_contents($tmpPath, $elfBytes);
        // mimeType=null forces real content-sniffing rather than trusting a
        // claimed type -- exactly the case this test is proving.
        $disguised = new UploadedFile($tmpPath, 'vacation-photo.png', null, null, true);

        $this->withHeaders($headers)->post('/api/orders', [
            'service_id' => $service->id,
            'answers' => [],
            'files' => ['room_photo' => $disguised],
        ])->assertStatus(422);
    }

    /**
     * Never Again: the per-type policy is genuinely config-driven -- tighten
     * config/media.php's text max_bytes at runtime and a previously-fine
     * upload starts failing, with no code change at all.
     */
    public function test_limits_are_config_driven(): void
    {
        ['order' => $order] = $this->ingestFixture(signingKey: 'storage-key'); // default declared output: text

        $smallText = UploadedFile::fake()->createWithContent('note.txt', str_repeat('a', 150));

        $this->withToken('storage-key')
            ->post('/api/storage', ['order_id' => $order->id, 'result_number' => 1, 'file' => $smallText])
            ->assertCreated();

        config(['media.types.text.max_bytes' => 100]);

        $this->withToken('storage-key')
            ->post('/api/storage', ['order_id' => $order->id, 'result_number' => 1, 'file' => $smallText])
            ->assertStatus(413);
    }
}
