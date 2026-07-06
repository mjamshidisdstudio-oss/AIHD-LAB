<?php

namespace App\Jobs;

use App\Models\Request;
use App\Services\Ingest\PollRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The first poll after dispatch. Delegates to PollRequest, which routes any
 * result through the single ingest door; the cron sweep (SweepPolls) drives
 * every subsequent poll, so this job never re-schedules itself.
 */
class PollRequestResult implements ShouldQueue
{
    use Queueable;

    public function __construct(public Request $request) {}

    public function handle(PollRequest $poller): void
    {
        $poller->handle($this->request);
    }
}
