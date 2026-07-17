<?php

namespace App\Jobs;

use App\Contracts\ExternalServiceClient;
use App\Enums\FailureStage;
use App\Enums\OrderSource;
use App\Enums\RequestStatus;
use App\Models\Order;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceVersion;
use App\Services\Ingest\FailRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Send a queued request to its service's external provider.
 *
 * Two invariants:
 *   - Idempotent: it acts only while the request is still `queued`, so a
 *     re-delivered job (at-least-once queue, manual retry) submits exactly once.
 *   - Concurrency-gated: it respects services.max_concurrent. At the cap it
 *     releases itself back to the queue with a delay instead of dropping the
 *     work.
 *
 * The queued-check, the in-flight count, and the transition to `sent` happen
 * together under a per-service cache lock, so two workers can't both admit past
 * the cap or both submit the same request.
 */
class DispatchRequest implements ShouldQueue
{
    use Queueable;

    public function __construct(public Request $request) {}

    public function handle(ExternalServiceClient $client): void
    {
        $request = $this->request->fresh(['order.service', 'order.version.outputs', 'order.inputs']);

        if ($request === null) {
            return;
        }

        $order = $request->order;
        $service = $order->service;
        $version = $order->version;

        $isAdminPreview = $order->source === OrderSource::AdminPreview;
        $decision = Cache::lock("dispatch:service:{$service->id}", 15)->get(
            fn () => $this->admit($request, $service, $isAdminPreview)
        );

        if ($decision === false) {
            // Could not acquire the gate lock; try again shortly.
            $this->release(5);

            return;
        }

        if ($decision === 'skip') {
            return;
        }

        if ($decision === 'release') {
            // At capacity: hand the work back to the queue, do not drop it.
            $this->release(max(1, $version->get_interval_s));

            return;
        }

        // Admitted (status is now `sent`). Submit OUTSIDE the lock. A
        // connection failure or non-2xx response (Http::throw()) must never
        // leave the request stuck at `sent` forever with coins deducted and
        // nothing to ever refund them -- route it through the same
        // fail/refund/strike path as any other failure.
        try {
            $externalOrderId = $client->submit($version, $this->buildPayload($order, $version));
        } catch (ConnectionException|RequestException $e) {
            Log::warning('DispatchRequest: submit() failed.', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            app(FailRequest::class)->handle($request, FailureStage::Post);

            return;
        }

        $request->update([
            'status' => RequestStatus::Awaiting,
            'external_order_id' => $externalOrderId,
        ]);

        PollRequestResult::dispatch($request)->delay(now()->addSeconds($version->get_interval_s));
    }

    /**
     * Decide (under the per-service lock) what to do with the request:
     * 'skip' (already dispatched), 'release' (at cap), or 'admitted' (marked sent).
     */
    private function admit(Request $request, Service $service, bool $isAdminPreview): string
    {
        $fresh = $request->fresh();

        if ($fresh === null || $fresh->status !== RequestStatus::Queued) {
            return 'skip';
        }

        // Admin-preview runs are cap-free in both directions: excluded from
        // the in-flight count (above) and never gated by it themselves.
        if (! $isAdminPreview && $this->inFlightCount($service) >= $service->max_concurrent) {
            return 'release';
        }

        $fresh->update([
            'status' => RequestStatus::Sent,
            'sent_at' => now(),
        ]);

        return 'admitted';
    }

    private function inFlightCount(Service $service): int
    {
        return Request::query()
            ->whereRelation('order', 'service_id', $service->id)
            // Admin-preview runs never contend for the concurrency cap — an
            // operator exercising a draft's live preview shouldn't be able to
            // starve real customer traffic, nor be starved by it.
            ->whereRelation('order', 'source', '!=', OrderSource::AdminPreview->value)
            ->whereIn('status', [
                RequestStatus::Sent->value,
                RequestStatus::Awaiting->value,
                RequestStatus::Polling->value,
            ])
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Order $order, ServiceVersion $version): array
    {
        return [
            'order_id' => $order->id,
            'inputs' => $order->inputs->map(fn ($input) => [
                'input_id' => $input->input_id,
                'value_text' => $input->value_text,
                'value_bool' => $input->value_bool,
            ])->all(),
            'expected_outputs' => $version->outputs->map(fn ($output) => [
                'result_number' => $output->result_number,
                'type' => $output->type->value,
            ])->all(),
        ];
    }
}
