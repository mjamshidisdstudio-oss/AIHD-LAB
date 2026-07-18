<?php

namespace App\Services\Ingest;

use App\Contracts\ExternalServiceClient;
use App\Enums\FailureStage;
use App\Enums\RequestStatus;
use App\Enums\ResultSource;
use App\Exceptions\External\ExternalServiceReportedFailureException;
use App\Models\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

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

        try {
            $result = $this->client->poll($version, (string) $request->external_order_id);
        } catch (ExternalServiceReportedFailureException $e) {
            // An explicit failure report is not "still pending" -- fail
            // immediately with FailureStage::Service (refund + strike happen
            // once), rather than grinding through the attempt budget toward a
            // misleading Timeout.
            Log::warning('PollRequest: provider reported failure.', ['request_id' => $request->id, 'reason' => $e->getMessage()]);
            $this->fail->handle($request, FailureStage::Service);

            return;
        } catch (ConnectionException|RequestException $e) {
            // Still counts toward the attempt budget above -- a
            // permanently-unreachable service must converge to Timeout on a
            // later call, never poll forever because every attempt throws
            // before get_poll_count ever advances.
            Log::warning('PollRequest: poll() failed.', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            $request->update([
                'status' => RequestStatus::Polling,
                'get_poll_count' => $request->get_poll_count + 1,
                'last_polled_at' => now(),
            ]);

            return;
        }

        $request->update([
            'status' => RequestStatus::Polling,
            'get_poll_count' => $request->get_poll_count + 1,
            'last_polled_at' => now(),
        ]);

        if ($result === null) {
            return;
        }

        foreach ($result->items as $item) {
            $outcome = $this->ingest->handle($request, $item, ResultSource::Poll, $result->latencyMs);

            // Poll deliveries have no webhook_deliveries receipt trail, so a
            // rejection has nowhere else to surface -- log it, same as any
            // other poll-time anomaly in this method.
            if ($outcome->wasRejected()) {
                Log::warning('PollRequest: result rejected.', [
                    'request_id' => $request->id,
                    'result_number' => $item->resultNumber,
                    'reason' => $outcome->rejectedReason,
                ]);
            }
        }
    }
}
