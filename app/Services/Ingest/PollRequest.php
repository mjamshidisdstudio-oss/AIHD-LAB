<?php

namespace App\Services\Ingest;

use App\Contracts\ExternalServiceClient;
use App\Enums\FailureStage;
use App\Enums\RequestStatus;
use App\Enums\ResultSource;
use App\Models\Request;

/**
 * Poll one request's external order and route any results through the single
 * ingest door. Never writes to `results` itself. Skips terminal requests, fails
 * (timeout) once the attempt budget is spent, and records each poll.
 */
class PollRequest
{
    public function __construct(
        private ExternalServiceClient $client,
        private IngestResult $ingest,
        private FailRequest $fail,
    ) {}

    public function handle(Request $request): void
    {
        $request = $request->fresh(['order.version.outputs']);

        if ($request === null
            || ! in_array($request->status, [RequestStatus::Awaiting, RequestStatus::Polling], true)) {
            return;
        }

        $version = $request->order->version;

        // Attempt budget spent -> timeout failure (refund + strike happen once).
        if ($request->get_poll_count >= $version->max_get_attempts) {
            $this->fail->handle($request, FailureStage::Timeout);

            return;
        }

        $result = $this->client->poll($version, (string) $request->external_order_id);

        $request->update([
            'status' => RequestStatus::Polling,
            'get_poll_count' => $request->get_poll_count + 1,
            'last_polled_at' => now(),
        ]);

        if ($result === null) {
            return;
        }

        foreach ($result->items as $item) {
            $this->ingest->handle($request, $item, ResultSource::Poll, $result->latencyMs);
        }
    }
}
