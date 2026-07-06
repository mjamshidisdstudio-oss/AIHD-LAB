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
