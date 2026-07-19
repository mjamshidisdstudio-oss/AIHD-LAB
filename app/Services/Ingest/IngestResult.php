<?php

namespace App\Services\Ingest;

use App\Actions\Storage\StoreMedia;
use App\Contracts\CoinService;
use App\Enums\FileKind;
use App\Enums\MediaType;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ResultSource;
use App\Events\OrderCompleted;
use App\Exceptions\Storage\MediaValidationException;
use App\Models\File;
use App\Models\Order;
use App\Models\Request;
use App\Models\Result;
use App\Models\ServiceOutput;
use App\Support\External\ExternalResultItem;
use App\Support\Ingest\IngestOutcome;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE SINGLE DOOR. Both the webhook path and the poll sweep persist results
 * only through here — nothing writes to `results` directly.
 *
 * Idempotency is a genuine UPSERT against UNIQUE(request_id, result_number),
 * not a select-then-insert (which races between the check and the write). The
 * ON DUPLICATE KEY clause re-assigns result_number to itself — a column that is
 * by definition already equal on a conflict — so it is a true no-op: a
 * duplicate delivery never overwrites the original row's content. We tell new
 * from duplicate by comparing the row's stored id against the id we proposed;
 * that comparison reads data the upsert already committed, so it never races
 * either. Media is only copied for a genuinely new result, so a duplicate
 * never touches storage.
 *
 * An order completes only when its result count reaches its version's declared
 * output count — checked under a row lock so the completion transition (and the
 * settle + broadcast that ride on it) happen exactly once, even when a webhook
 * and the sweep race on the same request.
 */
class IngestResult
{
    public function __construct(private CoinService $coins, private StoreMedia $storeMedia) {}

    public function handle(Request $request, ExternalResultItem $item, ResultSource $source, int $latencyMs): IngestOutcome
    {
        $referencedFile = null;
        $inlineFile = null;

        if ($item->hasMediaReference()) {
            $referencedFile = File::query()->find($item->mediaId);

            // media_id is an opaque token, not a secret -- a provider (buggy or
            // malicious) could name any file it can enumerate. Never link a
            // result to a file that wasn't uploaded for THIS order; reject the
            // whole delivery before it touches `results` at all, rather than
            // silently dropping just the file reference.
            if ($referencedFile === null || $referencedFile->order_id !== $request->order_id) {
                return IngestOutcome::rejected('invalid_media_reference');
            }
        } elseif ($item->isFile()) {
            // Inline bytes (content_base64), not a pre-uploaded media_id --
            // routed through the SAME shared StoreMedia path POST /api/storage
            // uses (config/media.php's per-type mime allow-list and size
            // ceiling, checked against the REAL content-sniffed mime, never
            // the delivery's own claimed one). Validated and written before
            // the upsert below, same as the media-reference check above, so a
            // rejected delivery never creates a `results` row at all.
            //
            // $item->type is an unvalidated string straight from the
            // delivery's own payload -- tryFrom (not from) so a provider
            // sending anything outside image/video/text is a normal
            // rejection, not an uncaught ValueError.
            $mediaType = MediaType::tryFrom($item->type);
            if ($mediaType === null) {
                return IngestOutcome::rejected('invalid_media');
            }

            try {
                $inlineFile = $this->storeInlineBytes($request, $item, $mediaType);
            } catch (MediaValidationException) {
                return IngestOutcome::rejected('invalid_media');
            }
        }

        $candidateId = (string) Str::uuid();

        DB::table('results')->upsert([
            [
                'id' => $candidateId,
                'request_id' => $request->id,
                'result_number' => $item->resultNumber,
                'type' => $item->type,
                'file_id' => null,
                'text_value' => $item->text,
                'source' => $source->value,
                'latency_ms' => $latencyMs,
                'received_at' => now(),
            ],
        ], ['request_id', 'result_number'], ['result_number']);

        $stored = Result::query()
            ->where('request_id', $request->id)
            ->where('result_number', $item->resultNumber)
            ->first();

        // The upsert hit an existing row (someone else's id survived) — the
        // classic UPSERT tell for "this was already there."
        if ($stored === null || $stored->id !== $candidateId) {
            return IngestOutcome::duplicate();
        }

        if ($inlineFile !== null) {
            $stored->update(['file_id' => $inlineFile->id]);
        } elseif ($referencedFile !== null) {
            // Already uploaded (and ownership-verified above) via POST
            // /storage -- link directly, never re-store its bytes.
            $stored->update(['file_id' => $referencedFile->id]);
        }

        return IngestOutcome::ingested($this->completeIfAllResultsIn($request));
    }

    /**
     * Writes inline-delivered bytes through StoreMedia by wrapping them in a
     * real UploadedFile pointed at a throwaway temp file -- this is what lets
     * getMimeType() genuinely content-sniff the bytes (Symfony's real
     * detection only runs against an actual file on disk), rather than
     * trusting whatever mime the delivery claims.
     */
    private function storeInlineBytes(Request $request, ExternalResultItem $item, MediaType $mediaType): File
    {
        $order = $request->relationLoaded('order') ? $request->order : $request->order()->firstOrFail();

        $tmpPath = tempnam(sys_get_temp_dir(), 'ingest-media-');
        file_put_contents($tmpPath, (string) $item->bytes);

        try {
            $uploadedFile = new UploadedFile($tmpPath, "result-{$item->resultNumber}", null, null, true);

            return $this->storeMedia->handle($order, $uploadedFile, FileKind::Result, $mediaType);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Complete the order iff its results now cover every declared output, and
     * do it exactly once. The row lock serialises concurrent ingests so only the
     * caller that crosses the threshold sees the transition and fires settle +
     * broadcast. A terminal order — completed OR failed — never transitions
     * again: a late result for an already-failed (and already-refunded) order
     * must not resurrect it into completed and settle a second time.
     */
    private function completeIfAllResultsIn(Request $request): bool
    {
        $justCompleted = DB::transaction(function () use ($request) {
            $order = Order::query()->whereKey($request->order_id)->lockForUpdate()->first();

            if ($order === null || $order->status !== OrderStatus::Processing) {
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
}
