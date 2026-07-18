<?php

namespace App\Http\Controllers;

use App\Enums\ResultSource;
use App\Enums\WebhookOutcome;
use App\Models\Request as ServiceRequest;
use App\Models\Service;
use App\Models\WebhookDelivery;
use App\Services\Ingest\IngestResult;
use App\Support\External\ExternalResultItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound result webhooks from external services.
 *
 * Every delivery — accepted or rejected — writes a webhook_deliveries receipt
 * with the raw body stored verbatim. The receipt never depends on parsing the
 * body, so a malformed payload still leaves evidence. Results are persisted only
 * through the single IngestResult door.
 */
class WebhookController extends Controller
{
    public function __construct(private IngestResult $ingest) {}

    public function results(Request $http, Service $service): JsonResponse
    {
        // Raw bytes, captured before any parsing.
        $raw = $http->getContent();

        // B1: verify HMAC over the RAW body, constant-time, BEFORE parsing.
        if (! $service->verifyWebhookSignature($raw, $http->header('X-Signature'))) {
            return $this->record($service, null, null, null, WebhookOutcome::InvalidSignature, 401, $raw);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return $this->record($service, null, null, null, WebhookOutcome::ValidationError, 422, $raw);
        }

        $externalOrderId = is_string($data['external_order_id'] ?? null) ? $data['external_order_id'] : null;
        $resultNumber = is_int($data['result_number'] ?? null) ? $data['result_number'] : null;
        $type = is_string($data['type'] ?? null) ? $data['type'] : null;

        if ($externalOrderId === null || $resultNumber === null || $type === null) {
            return $this->record($service, null, $externalOrderId, $resultNumber, WebhookOutcome::ValidationError, 422, $raw);
        }

        $request = ServiceRequest::query()
            ->where('external_order_id', $externalOrderId)
            ->first();

        if ($request === null || $request->order->service_id !== $service->id) {
            return $this->record($service, null, $externalOrderId, $resultNumber, WebhookOutcome::UnknownOrder, 404, $raw);
        }

        // Superseded by a newer attempt on the same order.
        $isStale = ServiceRequest::query()
            ->where('order_id', $request->order_id)
            ->where('attempt_no', '>', $request->attempt_no)
            ->exists();
        if ($isStale) {
            return $this->record($service, $request, $externalOrderId, $resultNumber, WebhookOutcome::StaleAttempt, 409, $raw);
        }

        $outcome = $this->ingest->handle(
            $request,
            $this->itemFrom($data, $resultNumber, $type),
            ResultSource::Webhook,
            is_int($data['latency_ms'] ?? null) ? $data['latency_ms'] : 0,
        );

        $webhookOutcome = match (true) {
            $outcome->wasRejected() => WebhookOutcome::InvalidMediaReference,
            $outcome->duplicate => WebhookOutcome::Duplicate,
            default => WebhookOutcome::Ingested,
        };

        return $this->record(
            $service,
            $request,
            $externalOrderId,
            $resultNumber,
            $webhookOutcome,
            $outcome->wasRejected() ? 403 : 200,
            $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function itemFrom(array $data, int $resultNumber, string $type): ExternalResultItem
    {
        $media = is_array($data['media'] ?? null) ? $data['media'] : [];

        return new ExternalResultItem(
            resultNumber: $resultNumber,
            type: $type,
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
            mime: is_string($media['mime'] ?? null) ? $media['mime'] : null,
            bytes: isset($media['content_base64']) && is_string($media['content_base64'])
                ? (base64_decode($media['content_base64'], true) ?: null)
                : null,
            // The preferred path for real media: the provider already uploaded
            // it via POST /storage and hands us back only the reference.
            mediaId: is_string($data['media_id'] ?? null) ? $data['media_id'] : null,
        );
    }

    private function record(
        Service $service,
        ?ServiceRequest $request,
        ?string $externalOrderId,
        ?int $resultNumber,
        WebhookOutcome $outcome,
        int $httpStatus,
        string $rawBody,
    ): JsonResponse {
        WebhookDelivery::create([
            'service_id' => $service->id,
            'request_id' => $request?->id,
            'external_order_id' => $externalOrderId,
            'result_number' => $resultNumber,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'raw_body' => $rawBody,
        ]);

        return response()->json(['outcome' => $outcome->value], $httpStatus);
    }
}
