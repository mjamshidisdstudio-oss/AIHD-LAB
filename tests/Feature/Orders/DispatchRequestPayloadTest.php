<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\ExternalServiceClient;
use App\Enums\FileKind;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceInputType;
use App\Enums\ServiceOutputType;
use App\Jobs\DispatchRequest;
use App\Jobs\PollRequestResult;
use App\Models\File;
use App\Models\Order;
use App\Models\OrderInput;
use App\Models\OrderInputFile;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Never Again: a genuinely external provider has no back-channel into our DB --
 * the only way it can learn which uploaded photo belongs to an order is the
 * submit payload itself. Before this test, buildPayload() sent an input's raw
 * UUID id and scalar value only; an image input's attached file (tracked in
 * order_input_files) was never included, so an external service had no way to
 * resolve a media_id to fetch via GET /storage/{media_id}. It was never caught
 * because the only "external service" ever exercised (DevServiceController)
 * fabricates fixed output bytes and never reads the input at all.
 */
class DispatchRequestPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_payload_includes_input_slugs_and_attached_file_media_ids(): void
    {
        Queue::fake([PollRequestResult::class]);
        Http::fake([
            '*' => Http::response(['external_order_id' => 'ext-abc', 'status' => 'accepted']),
        ]);

        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        $imageInput = ServiceInput::factory()->create([
            'service_version_id' => $version->id,
            'slug' => 'room_photo',
            'type' => ServiceInputType::Image,
        ]);
        $textInput = ServiceInput::factory()->create([
            'service_version_id' => $version->id,
            'slug' => 'notes',
            'type' => ServiceInputType::Text,
        ]);

        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Image,
        ]);
        app(PublishVersion::class)->handle($version);

        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
        ]);

        $orderImageInput = OrderInput::factory()->empty()->create([
            'order_id' => $order->id,
            'input_id' => $imageInput->id,
        ]);
        OrderInput::factory()->create([
            'order_id' => $order->id,
            'input_id' => $textInput->id,
            'value_text' => 'make it cozy',
        ]);

        $file = File::factory()->input()->create(['order_id' => $order->id, 'kind' => FileKind::Input]);
        OrderInputFile::factory()->create([
            'order_input_id' => $orderImageInput->id,
            'file_id' => $file->id,
            'position' => 0,
        ]);

        $request = Request::factory()->create([
            'order_id' => $order->id,
            'attempt_no' => 1,
            'status' => RequestStatus::Queued,
            'external_order_id' => null,
        ]);

        (new DispatchRequest($request))->handle(app(ExternalServiceClient::class));

        Http::assertSent(function ($sent) use ($file, $imageInput) {
            $body = $sent->data();

            $slugs = collect($body['inputs'])->pluck('slug')->all();
            if (! in_array('room_photo', $slugs, true) || ! in_array('notes', $slugs, true)) {
                return false;
            }

            $mediaEntry = collect($body['media_ids'])->firstWhere('slug', 'room_photo');

            return $mediaEntry !== null
                && $mediaEntry['media_id'] === $file->id
                && $mediaEntry['input_id'] === $imageInput->id;
        });
    }
}
