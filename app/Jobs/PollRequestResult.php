<?php

namespace App\Jobs;

use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Enums\FailureStage;
use App\Enums\FileKind;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ResultSource;
use App\Models\File;
use App\Models\Order;
use App\Models\Request;
use App\Support\External\ExternalResult;
use App\Support\External\ExternalResultItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Poll the external provider for a request's results. Idempotent: only acts
 * while the request is awaiting/polling. On a ready result it persists the
 * results (and files) and completes the order in one transaction; while still
 * pending it re-schedules itself until max_get_attempts, then fails the request
 * (timeout) and refunds the coins.
 */
class PollRequestResult implements ShouldQueue
{
    use Queueable;

    public function __construct(public Request $request) {}

    public function handle(ExternalServiceClient $client, CoinService $coins): void
    {
        $request = $this->request->fresh(['order.version.outputs', 'order.service']);

        if ($request === null) {
            return;
        }

        // Idempotent: a completed/failed request is never re-processed.
        if (! in_array($request->status, [RequestStatus::Awaiting, RequestStatus::Polling], true)) {
            return;
        }

        $order = $request->order;
        $version = $order->version;

        $result = $client->poll($version, (string) $request->external_order_id);

        if ($result === null) {
            $this->handlePending($request, $order, $coins);

            return;
        }

        $this->complete($request, $order, $result);
    }

    private function handlePending(Request $request, Order $order, CoinService $coins): void
    {
        $pollCount = $request->get_poll_count + 1;

        $request->update([
            'status' => RequestStatus::Polling,
            'get_poll_count' => $pollCount,
            'last_polled_at' => now(),
        ]);

        if ($pollCount >= $order->version->max_get_attempts) {
            $this->fail($request, $order, $coins);

            return;
        }

        self::dispatch($request)->delay(now()->addSeconds($order->version->get_interval_s));
    }

    private function complete(Request $request, Order $order, ExternalResult $result): void
    {
        DB::transaction(function () use ($request, $order, $result) {
            foreach ($result->items as $item) {
                $this->persistResult($request, $order, $item, $result->latencyMs);
            }

            $request->update([
                'status' => RequestStatus::Completed,
                'last_polled_at' => now(),
            ]);

            $order->update([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
            ]);
        });

        // Refresh the service's cached health/latency read columns.
        $order->service()->update([
            'avg_latency_ms' => $result->latencyMs,
            'consecutive_failures' => 0,
        ]);
    }

    private function persistResult(Request $request, Order $order, ExternalResultItem $item, int $latencyMs): void
    {
        $fileId = null;
        $textValue = null;

        if ($item->isFile()) {
            $path = "results/{$order->id}/{$item->resultNumber}{$this->extensionFor($item->mime)}";
            Storage::disk('media')->put($path, $item->bytes);

            $fileId = File::create([
                'kind' => FileKind::Result,
                'disk' => 'media',
                'order_id' => $order->id,
                'mime' => $item->mime,
                'path' => $path,
                'size' => strlen((string) $item->bytes),
            ])->id;
        } else {
            $textValue = $item->text;
        }

        $request->results()->create([
            'result_number' => $item->resultNumber,
            'type' => $item->type,
            'file_id' => $fileId,
            'text_value' => $textValue,
            'source' => ResultSource::Poll,
            'latency_ms' => $latencyMs,
        ]);
    }

    private function fail(Request $request, Order $order, CoinService $coins): void
    {
        DB::transaction(function () use ($request, $order) {
            $request->update([
                'status' => RequestStatus::Failed,
                'failure_stage' => FailureStage::Timeout,
            ]);
            $order->update(['status' => OrderStatus::Failed]);
            $order->service()->increment('consecutive_failures');
        });

        if ($order->coin_txn_ref !== null) {
            $coins->refund($order->coin_txn_ref);
        }
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            'video/mp4' => '.mp4',
            default => '',
        };
    }
}
