<?php

namespace App\Services\Ingest;

use App\Contracts\CoinService;
use App\Enums\FileKind;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ResultSource;
use App\Events\OrderCompleted;
use App\Models\File;
use App\Models\Order;
use App\Models\Request;
use App\Models\Result;
use App\Models\ServiceOutput;
use App\Support\External\ExternalResultItem;
use App\Support\Ingest\IngestOutcome;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * THE SINGLE DOOR. Both the webhook path and the poll sweep persist results
 * only through here — nothing writes to `results` directly.
 *
 * Idempotency rests on the UNIQUE(request_id, result_number) key: a new result
 * inserts; a duplicate delivery hits the unique constraint and becomes a no-op
 * (no second row, no exception surfaced, no downstream side effects). Media is
 * only copied for a genuinely new result, so a duplicate never touches storage.
 *
 * An order completes only when its result count reaches its version's declared
 * output count — checked under a row lock so the completion transition (and the
 * settle + broadcast that ride on it) happen exactly once, even when a webhook
 * and the sweep race on the same request.
 */
class IngestResult
{
    public function __construct(private CoinService $coins) {}

    public function handle(Request $request, ExternalResultItem $item, ResultSource $source, int $latencyMs): IngestOutcome
    {
        // Insert the result first (media attached afterwards) so a duplicate is
        // caught before any file is written — no orphaned storage objects.
        try {
            $result = Result::create([
                'request_id' => $request->id,
                'result_number' => $item->resultNumber,
                'type' => $item->type,
                'file_id' => null,
                'text_value' => $item->text,
                'source' => $source,
                'latency_ms' => $latencyMs,
            ]);
        } catch (UniqueConstraintViolationException) {
            return IngestOutcome::duplicate();
        }

        if ($item->isFile()) {
            $path = "results/{$request->order_id}/{$item->resultNumber}{$this->extensionFor($item->mime)}";
            Storage::disk('media')->put($path, $item->bytes);

            $file = File::create([
                'kind' => FileKind::Result,
                'disk' => 'media',
                'order_id' => $request->order_id,
                'mime' => $item->mime,
                'path' => $path,
                'size' => strlen((string) $item->bytes),
            ]);

            $result->update(['file_id' => $file->id]);
        }

        return IngestOutcome::ingested($this->completeIfAllResultsIn($request));
    }

    /**
     * Complete the order iff its results now cover every declared output, and
     * do it exactly once. The row lock serialises concurrent ingests so only the
     * caller that crosses the threshold sees the transition and fires settle +
     * broadcast.
     */
    private function completeIfAllResultsIn(Request $request): bool
    {
        $justCompleted = DB::transaction(function () use ($request) {
            $order = Order::query()->whereKey($request->order_id)->lockForUpdate()->first();

            if ($order === null || $order->status === OrderStatus::Completed) {
                return false;
            }

            $expected = ServiceOutput::query()
                ->where('service_version_id', $order->service_version_id)
                ->count();
            $have = Result::query()->where('request_id', $request->id)->count();

            if ($expected === 0 || $have < $expected) {
                return false;
            }

            Request::query()->whereKey($request->id)->update(['status' => RequestStatus::Completed->value]);
            $order->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);

            return true;
        });

        if ($justCompleted) {
            $this->onCompleted($request);
        }

        return $justCompleted;
    }

    private function onCompleted(Request $request): void
    {
        /** @var Order $order */
        $order = Order::query()->with('service')->findOrFail($request->order_id);

        if ($order->coin_txn_ref !== null) {
            $this->coins->settle($order->coin_txn_ref);
        }

        // Healthy again: clear the failure streak (status re-enable is Phase 2's
        // publish concern, per H2).
        $order->service()->update(['consecutive_failures' => 0]);

        OrderCompleted::dispatch($order);
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
