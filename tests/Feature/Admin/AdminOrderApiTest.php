<?php

namespace Tests\Feature\Admin;

use App\Enums\EntryMode;
use App\Enums\FailureStage;
use App\Enums\InteractionKind;
use App\Enums\OrderSource;
use App\Enums\RequestStatus;
use App\Enums\ServiceOutputType;
use App\Models\File;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Request as OrderRequest;
use App\Models\Result;
use App\Models\Service;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_list_or_view_orders(): void
    {
        $service = Service::factory()->create();
        $order = Order::factory()->create(['service_id' => $service->id]);

        $this->getJson("/api/admin/services/{$service->id}/orders")->assertUnauthorized();
        $this->getJson("/api/admin/orders/{$order->id}")->assertUnauthorized();
    }

    public function test_order_list_filters_by_source_and_entry_mode(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();

        Order::factory()->create(['service_id' => $service->id, 'source' => OrderSource::Site, 'entry_mode' => EntryMode::Wizard]);
        Order::factory()->create(['service_id' => $service->id, 'source' => OrderSource::Site, 'entry_mode' => EntryMode::Chat]);
        Order::factory()->adminPreview()->create(['service_id' => $service->id, 'entry_mode' => EntryMode::Wizard]);

        $bySource = $this->getJson("/api/admin/services/{$service->id}/orders?source=admin_preview")->assertOk();
        $this->assertCount(1, $bySource->json('data'));
        $this->assertSame('admin_preview', $bySource->json('data.0.source'));

        $byEntryMode = $this->getJson("/api/admin/services/{$service->id}/orders?entry_mode=chat")->assertOk();
        $this->assertCount(1, $byEntryMode->json('data'));
        $this->assertSame('chat', $byEntryMode->json('data.0.entry_mode'));

        $combined = $this->getJson("/api/admin/services/{$service->id}/orders?source=site&entry_mode=wizard")->assertOk();
        $this->assertCount(1, $combined->json('data'));

        $all = $this->getJson("/api/admin/services/{$service->id}/orders")->assertOk();
        $this->assertCount(3, $all->json('data'));
        $this->assertSame(3, $all->json('meta_stats.total'));
    }

    public function test_order_list_only_returns_orders_for_the_given_service(): void
    {
        $this->actingAsAdmin();
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();
        Order::factory()->create(['service_id' => $serviceA->id]);
        Order::factory()->create(['service_id' => $serviceB->id]);

        $response = $this->getJson("/api/admin/services/{$serviceA->id}/orders")->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_order_detail_exposes_requests_results_webhooks_inputs_and_outputs(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);
        ServiceOutput::factory()->create([
            'service_version_id' => $version->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Image,
        ]);

        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'source' => OrderSource::Site,
            'entry_mode' => EntryMode::Chat,
        ]);

        $failedRequest = OrderRequest::factory()->failed(FailureStage::Timeout)->create([
            'order_id' => $order->id,
            'attempt_no' => 1,
        ]);
        WebhookDelivery::factory()->invalidSignature()->create([
            'service_id' => $service->id,
            'request_id' => $failedRequest->id,
            'raw_body' => '{"bad": "signature"}',
        ]);

        $successRequest = OrderRequest::factory()->create([
            'order_id' => $order->id,
            'attempt_no' => 2,
            'status' => RequestStatus::Completed,
        ]);
        $result = Result::factory()->create([
            'request_id' => $successRequest->id,
            'result_number' => 1,
        ]);
        Interaction::create([
            'kind' => InteractionKind::Download,
            'user_ref' => $order->user_ref,
            'service_id' => $service->id,
            'order_id' => $order->id,
            'result_id' => $result->id,
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/admin/orders/{$order->id}")->assertOk();

        $response->assertJsonPath('data.source', 'site');
        $response->assertJsonPath('data.entry_mode', 'chat');
        $this->assertCount(2, $response->json('data.requests'));

        $requestsByAttempt = collect($response->json('data.requests'))->keyBy('attempt_no');
        $this->assertSame('failed', $requestsByAttempt[1]['status']);
        $this->assertSame('timeout', $requestsByAttempt[1]['failure_stage']);
        $this->assertCount(1, $requestsByAttempt[1]['webhook_deliveries']);
        $this->assertSame('invalid_signature', $requestsByAttempt[1]['webhook_deliveries'][0]['outcome']);
        $this->assertSame('{"bad": "signature"}', $requestsByAttempt[1]['webhook_deliveries'][0]['raw_body']);

        $outputs = $response->json('data.outputs');
        $this->assertCount(1, $outputs);
        $this->assertTrue($outputs[0]['has_result']);
        $this->assertSame(1, $outputs[0]['download_count']);
    }

    public function test_admin_result_download_streams_the_file_without_logging_an_interaction(): void
    {
        $this->actingAsAdmin();
        $order = Order::factory()->create();
        $file = File::factory()->result()->create([
            'order_id' => $order->id,
            'disk' => 'local',
            'path' => 'admin-download-test.png',
            'mime' => 'image/png',
        ]);
        Storage::disk('local')->put('admin-download-test.png', 'fake-bytes');
        $request = OrderRequest::factory()->create(['order_id' => $order->id]);
        $result = Result::factory()->create(['request_id' => $request->id, 'file_id' => $file->id]);

        $this->get("/api/admin/results/{$result->id}/download")->assertOk();

        $this->assertSame(0, Interaction::where('kind', InteractionKind::Download)->count());
    }
}
