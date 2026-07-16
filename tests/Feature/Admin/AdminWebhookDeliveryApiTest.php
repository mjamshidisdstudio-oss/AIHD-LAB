<?php

namespace Tests\Feature\Admin;

use App\Enums\WebhookOutcome;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminWebhookDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_list_or_view_webhook_deliveries(): void
    {
        $service = Service::factory()->create();
        $delivery = WebhookDelivery::factory()->create(['service_id' => $service->id]);

        $this->getJson("/api/admin/services/{$service->id}/webhook-deliveries")->assertUnauthorized();
        $this->getJson("/api/admin/webhook-deliveries/{$delivery->id}")->assertUnauthorized();
    }

    public function test_a_failed_delivery_is_findable_by_outcome_with_its_raw_body(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();

        WebhookDelivery::factory()->create([
            'service_id' => $service->id,
            'outcome' => WebhookOutcome::Ingested,
        ]);
        $rejected = WebhookDelivery::factory()->invalidSignature()->create([
            'service_id' => $service->id,
            'external_order_id' => 'ext-order-42',
            'raw_body' => '{"external_order_id":"ext-order-42","tampered":true}',
        ]);

        $response = $this->getJson("/api/admin/services/{$service->id}/webhook-deliveries?outcome=invalid_signature")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($rejected->id, $response->json('data.0.id'));
        $this->assertSame(401, $response->json('data.0.http_status'));
        $this->assertSame('{"external_order_id":"ext-order-42","tampered":true}', $response->json('data.0.raw_body'));
    }

    public function test_a_delivery_is_findable_by_external_order_id_even_with_no_matching_request(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();

        WebhookDelivery::factory()->create([
            'service_id' => $service->id,
            'request_id' => null,
            'external_order_id' => 'unknown-order-99',
            'outcome' => WebhookOutcome::UnknownOrder,
        ]);

        $response = $this->getJson("/api/admin/services/{$service->id}/webhook-deliveries?external_order_id=unknown-order-99")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.request_id'));
    }

    public function test_deliveries_are_scoped_to_the_given_service(): void
    {
        $this->actingAsAdmin();
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();
        WebhookDelivery::factory()->create(['service_id' => $serviceA->id]);
        WebhookDelivery::factory()->create(['service_id' => $serviceB->id]);

        $response = $this->getJson("/api/admin/services/{$serviceA->id}/webhook-deliveries")->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_returns_a_single_delivery_by_id(): void
    {
        $this->actingAsAdmin();
        $delivery = WebhookDelivery::factory()->create();

        $this->getJson("/api/admin/webhook-deliveries/{$delivery->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $delivery->id);
    }
}
