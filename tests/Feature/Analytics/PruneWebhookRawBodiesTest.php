<?php

namespace Tests\Feature\Analytics;

use App\Enums\WebhookOutcome;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneWebhookRawBodiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_body_nulled_after_30_days_but_receipt_kept(): void
    {
        $old = WebhookDelivery::factory()->create([
            'raw_body' => '{"payload": "aged out"}',
            'outcome' => WebhookOutcome::Ingested,
            'http_status' => 200,
            'received_at' => now()->subDays(31),
        ]);
        $recent = WebhookDelivery::factory()->create([
            'raw_body' => '{"payload": "still fresh"}',
            'outcome' => WebhookOutcome::Ingested,
            'http_status' => 200,
            'received_at' => now()->subDays(29),
        ]);
        $boundary = WebhookDelivery::factory()->create([
            'raw_body' => '{"payload": "exactly 30 days old"}',
            'received_at' => now()->subDays(30),
        ]);

        $this->artisan('retention:prune-webhook-bodies')->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->raw_body);
        $this->assertSame(WebhookOutcome::Ingested, $old->outcome);
        $this->assertSame(200, $old->http_status);
        $this->assertNotNull($old->received_at);

        $recent->refresh();
        $this->assertSame('{"payload": "still fresh"}', $recent->raw_body);

        // Exactly 30 days old is not yet "after 30 days" -- kept for now.
        $boundary->refresh();
        $this->assertNotNull($boundary->raw_body);
    }

    public function test_rerun_is_a_no_op_once_already_pruned(): void
    {
        $old = WebhookDelivery::factory()->create([
            'raw_body' => '{"payload": "aged out"}',
            'received_at' => now()->subDays(45),
        ]);

        $this->artisan('retention:prune-webhook-bodies')->assertSuccessful();
        $this->artisan('retention:prune-webhook-bodies')->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->raw_body);
    }
}
