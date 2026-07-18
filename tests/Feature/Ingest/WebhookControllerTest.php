<?php

namespace Tests\Feature\Ingest;

use App\Contracts\CoinService;
use App\Enums\FailureStage;
use App\Enums\FileKind;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\WebhookOutcome;
use App\Models\File;
use App\Models\Order;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsIngestFixtures;
use Tests\TestCase;

/**
 * Every inbound webhook — accepted or rejected — must leave a receipt with the
 * raw body verbatim, and the HMAC signature must be checked against the RAW
 * bytes before any parsing is attempted. Parse-then-verify is the classic
 * bypass: an attacker can craft a body that parses however they like as long
 * as *something* validates first, or a malformed body can crash the parser
 * before the (correct) rejection ever gets recorded.
 */
class WebhookControllerTest extends TestCase
{
    use BuildsIngestFixtures, RefreshDatabase;

    /**
     * Never Again: the signature is checked over the RAW body before any JSON
     * parsing is attempted. A malformed, unparseable body with a WRONG
     * signature must be rejected as invalid_signature — not crash while
     * attempting to parse it, and not be judged on parsed content it never
     * reaches.
     */
    public function test_signature_verified_on_raw_body_before_parsing(): void
    {
        ['service' => $service] = $this->ingestFixture(signingKey: 'sig-key');

        $rawBody = '{this is not valid json, and the signature below is wrong}';

        $response = $this->postRaw(
            "/api/webhooks/{$service->id}/results",
            $rawBody,
            ['X-Signature' => 'not-the-real-signature'],
        );

        $response->assertStatus(401)->assertJsonPath('outcome', 'invalid_signature');

        $this->assertDatabaseHas('webhook_deliveries', [
            'service_id' => $service->id,
            'outcome' => WebhookOutcome::InvalidSignature->value,
            'http_status' => 401,
            'raw_body' => $rawBody,
        ]);
    }

    /**
     * Never Again: a malformed body — one that fails to parse as JSON even
     * once past a valid signature — still writes a receipt. The receipt does
     * not depend on parsing succeeding.
     */
    public function test_malformed_body_still_writes_a_receipt(): void
    {
        ['service' => $service] = $this->ingestFixture(signingKey: 'sig-key');

        $rawBody = 'not json at all — just bytes';
        $signature = $this->hmacFor($rawBody, 'sig-key');

        $response = $this->postRaw(
            "/api/webhooks/{$service->id}/results",
            $rawBody,
            ['X-Signature' => $signature],
        );

        $response->assertStatus(422)->assertJsonPath('outcome', 'validation_error');

        $this->assertDatabaseHas('webhook_deliveries', [
            'service_id' => $service->id,
            'outcome' => WebhookOutcome::ValidationError->value,
            'http_status' => 422,
            'raw_body' => $rawBody,
        ]);
    }

    /**
     * Every classified outcome writes exactly one receipt, whether the
     * delivery was accepted or rejected.
     */
    public function test_every_reject_outcome_writes_a_receipt(): void
    {
        ['service' => $service, 'request' => $request] = $this->ingestFixture(signingKey: 'sig-key');

        // unknown_order: a well-signed, well-formed body for an
        // external_order_id we have never heard of.
        $body = json_encode([
            'external_order_id' => 'does-not-exist',
            'result_number' => 1,
            'type' => 'text',
            'text' => 'hi',
        ]);
        $this->postRaw("/api/webhooks/{$service->id}/results", $body, [
            'X-Signature' => $this->hmacFor($body, 'sig-key'),
        ])->assertStatus(404)->assertJsonPath('outcome', 'unknown_order');

        // stale_attempt: a newer attempt on the same order supersedes this one.
        $request->order->requests()->create([
            'attempt_no' => $request->attempt_no + 1,
            'status' => 'queued',
        ]);
        $staleBody = json_encode([
            'external_order_id' => $request->external_order_id,
            'result_number' => 1,
            'type' => 'text',
            'text' => 'late',
        ]);
        $this->postRaw("/api/webhooks/{$service->id}/results", $staleBody, [
            'X-Signature' => $this->hmacFor($staleBody, 'sig-key'),
        ])->assertStatus(409)->assertJsonPath('outcome', 'stale_attempt');

        $this->assertDatabaseHas('webhook_deliveries', ['service_id' => $service->id, 'outcome' => WebhookOutcome::UnknownOrder->value]);
        $this->assertDatabaseHas('webhook_deliveries', ['service_id' => $service->id, 'outcome' => WebhookOutcome::StaleAttempt->value]);
    }

    public function test_a_valid_delivery_is_ingested_and_receipted(): void
    {
        ['service' => $service, 'request' => $request] = $this->ingestFixture(signingKey: 'sig-key');

        $body = json_encode([
            'external_order_id' => $request->external_order_id,
            'result_number' => 1,
            'type' => 'text',
            'text' => 'the result',
        ]);

        $this->postRaw("/api/webhooks/{$service->id}/results", $body, [
            'X-Signature' => $this->hmacFor($body, 'sig-key'),
        ])->assertStatus(200)->assertJsonPath('outcome', 'ingested');

        $this->assertDatabaseHas('webhook_deliveries', [
            'service_id' => $service->id,
            'request_id' => $request->id,
            'outcome' => WebhookOutcome::Ingested->value,
            'raw_body' => $body,
        ]);
        $this->assertDatabaseHas('results', ['request_id' => $request->id, 'result_number' => 1, 'text_value' => 'the result']);
    }

    public function test_a_duplicate_delivery_is_receipted_as_duplicate(): void
    {
        ['service' => $service, 'request' => $request] = $this->ingestFixture(signingKey: 'sig-key');

        $body = json_encode([
            'external_order_id' => $request->external_order_id,
            'result_number' => 1,
            'type' => 'text',
            'text' => 'the result',
        ]);
        $headers = ['X-Signature' => $this->hmacFor($body, 'sig-key')];

        $this->postRaw("/api/webhooks/{$service->id}/results", $body, $headers)->assertStatus(200);
        $this->postRaw("/api/webhooks/{$service->id}/results", $body, $headers)
            ->assertStatus(200)->assertJsonPath('outcome', 'duplicate');

        $this->assertSame(1, WebhookDelivery::where('outcome', WebhookOutcome::Ingested->value)->count());
        $this->assertSame(1, WebhookDelivery::where('outcome', WebhookOutcome::Duplicate->value)->count());
    }

    /**
     * A result delivered by media_id reference (the pre-upload-then-reference
     * path) is accepted and linked when the file was uploaded for this order.
     */
    public function test_a_media_id_delivery_for_this_orders_own_file_is_ingested(): void
    {
        ['service' => $service, 'request' => $request, 'order' => $order] = $this->ingestFixture(signingKey: 'sig-key');
        $file = File::factory()->create(['order_id' => $order->id, 'kind' => FileKind::Result]);

        $body = json_encode([
            'external_order_id' => $request->external_order_id,
            'result_number' => 1,
            'type' => 'image',
            'media_id' => $file->id,
        ]);

        $this->postRaw("/api/webhooks/{$service->id}/results", $body, [
            'X-Signature' => $this->hmacFor($body, 'sig-key'),
        ])->assertStatus(200)->assertJsonPath('outcome', 'ingested');

        $this->assertDatabaseHas('results', ['request_id' => $request->id, 'result_number' => 1, 'file_id' => $file->id]);
    }

    /**
     * Never Again: a media_id delivery is REJECTED, over HTTP, when the
     * referenced file was uploaded for a different order. media_id is an
     * opaque token, not a secret -- ownership must be verified server-side,
     * not trusted from the caller.
     */
    public function test_a_media_id_delivery_referencing_another_orders_file_is_rejected(): void
    {
        ['service' => $service, 'request' => $request] = $this->ingestFixture(signingKey: 'sig-key');
        $foreignFile = File::factory()->create(['order_id' => Order::factory()->create()->id, 'kind' => FileKind::Result]);

        $body = json_encode([
            'external_order_id' => $request->external_order_id,
            'result_number' => 1,
            'type' => 'image',
            'media_id' => $foreignFile->id,
        ]);

        $response = $this->postRaw("/api/webhooks/{$service->id}/results", $body, [
            'X-Signature' => $this->hmacFor($body, 'sig-key'),
        ]);

        $response->assertStatus(403)->assertJsonPath('outcome', 'invalid_media_reference');

        $this->assertDatabaseHas('webhook_deliveries', [
            'service_id' => $service->id,
            'outcome' => WebhookOutcome::InvalidMediaReference->value,
            'http_status' => 403,
        ]);
        $this->assertDatabaseMissing('results', ['request_id' => $request->id, 'result_number' => 1]);
    }

    /**
     * Never Again: a provider reporting an explicit failure over the webhook
     * (rather than a result) must fail the order with FailureStage::Service,
     * refund coins, and record a strike -- the same as any other failure
     * path -- not be rejected as validation_error for lacking a
     * result_number/type it was never going to send.
     */
    public function test_a_failure_report_webhook_fails_the_order_with_service_stage(): void
    {
        ['service' => $service, 'request' => $request, 'order' => $order] = $this->ingestFixture(
            signingKey: 'sig-key',
            orderOverrides: ['coin_txn_ref' => 'txn-webhook-fail'],
        );
        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('refund')->once()->with('txn-webhook-fail');
        $coins->shouldNotReceive('settle');
        $this->app->instance(CoinService::class, $coins);

        $body = json_encode([
            'external_order_id' => $request->external_order_id,
            'status' => 'failed',
            'reason' => 'model unavailable',
        ]);

        $response = $this->postRaw("/api/webhooks/{$service->id}/results", $body, [
            'X-Signature' => $this->hmacFor($body, 'sig-key'),
        ]);

        $response->assertStatus(200)->assertJsonPath('outcome', 'failure_reported');

        $this->assertDatabaseHas('webhook_deliveries', [
            'service_id' => $service->id,
            'outcome' => WebhookOutcome::FailureReported->value,
            'http_status' => 200,
        ]);
        $this->assertSame(RequestStatus::Failed, $request->refresh()->status);
        $this->assertSame(FailureStage::Service, $request->failure_stage);
        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
    }
}
