<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll sweep: the cron safety net that pulls results for in-flight requests
// that are due, routing them through the single ingest door.
Schedule::command('poll:sweep')
    ->everyMinute()
    ->withoutOverlapping();

// Refreshes the marketplace grid's cached columns (votes, avg_latency_ms,
// trending_rank). Every 10 minutes is fresh enough for a "trending" ranking
// and vote/latency counts that only move as fast as real orders and votes
// come in -- no need for per-minute cadence like the poll sweep.
Schedule::command('services:refresh-metrics')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Retention janitor: a daily off-peak run is plenty for a 30-day-aged cutoff
// that never needs to be enforced to the minute.
Schedule::command('retention:prune-webhook-bodies')
    ->dailyAt('03:15')
    ->withoutOverlapping();
