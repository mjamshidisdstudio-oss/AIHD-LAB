<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Services\Ingest\PollRequest;
use Illuminate\Console\Command;

/**
 * Cron safety net (~every minute): polls every in-flight request that is due
 * for another poll (respecting get_interval_s), routing any result through the
 * SAME ingest door as the webhook path. Terminal requests are excluded by the
 * status filter; PollRequest fails those past their attempt budget.
 */
class SweepPolls extends Command
{
    protected $signature = 'poll:sweep';

    protected $description = 'Poll in-flight requests that are due and ingest any results.';

    public function handle(PollRequest $poller): int
    {
        $due = Request::query()
            ->join('orders', 'orders.id', '=', 'requests.order_id')
            ->join('service_versions', 'service_versions.id', '=', 'orders.service_version_id')
            ->whereIn('requests.status', [
                RequestStatus::Awaiting->value,
                RequestStatus::Polling->value,
            ])
            ->where(function ($q) {
                // Never polled, or the interval has elapsed since the last poll.
                $q->whereNull('requests.last_polled_at')
                    ->orWhereRaw('requests.last_polled_at + INTERVAL service_versions.get_interval_s SECOND <= NOW()');
            })
            ->select('requests.*')
            ->get();

        foreach ($due as $request) {
            $poller->handle($request);
        }

        $this->info("Swept {$due->count()} due request(s).");

        return self::SUCCESS;
    }
}
