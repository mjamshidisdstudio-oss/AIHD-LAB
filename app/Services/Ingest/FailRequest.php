<?php

namespace App\Services\Ingest;

use App\Contracts\CoinService;
use App\Enums\FailureStage;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceStatus;
use App\Models\Order;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fail a request/order exactly once. The terminal transition (order -> failed)
 * is guarded under a row lock so the refund and the failure strike ride on it
 * once, no matter how many observers (webhook, sweep) report the same failure.
 *
 * A third consecutive failure auto-disables the service; a later success resets
 * the counter (IngestResult), and re-enabling the status is Phase 2's publish
 * concern (H2).
 */
class FailRequest
{
    private const AUTO_DISABLE_THRESHOLD = 3;

    public function __construct(private CoinService $coins) {}

    public function handle(Request $request, FailureStage $stage): bool
    {
        $justFailed = DB::transaction(function () use ($request, $stage) {
            $order = Order::query()->whereKey($request->order_id)->lockForUpdate()->first();

            if ($order === null || in_array($order->status, [OrderStatus::Completed, OrderStatus::Failed], true)) {
                return false;
            }

            Request::query()->whereKey($request->id)->update([
                'status' => RequestStatus::Failed->value,
                'failure_stage' => $stage->value,
            ]);
            $order->update(['status' => OrderStatus::Failed]);

            return true;
        });

        if ($justFailed) {
            $this->onFailed($request);
        }

        return $justFailed;
    }

    private function onFailed(Request $request): void
    {
        /** @var Order $order */
        $order = Order::query()->with('service')->findOrFail($request->order_id);

        if ($order->coin_txn_ref !== null) {
            $this->coins->refund($order->coin_txn_ref);
        }

        $service = $order->service;
        $service->increment('consecutive_failures');
        $service->refresh();

        if ($service->consecutive_failures >= self::AUTO_DISABLE_THRESHOLD
            && $service->status !== ServiceStatus::AutoDisabled) {
            $service->update(['status' => ServiceStatus::AutoDisabled]);
        }
    }
}
