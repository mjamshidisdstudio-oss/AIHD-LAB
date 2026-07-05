<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * State machine for a single external attempt (POST then GET polling):
 * queued -> sent -> awaiting -> polling -> completed | failed.
 */
enum RequestStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Sent = 'sent';
    case Awaiting = 'awaiting';
    case Polling = 'polling';
    case Completed = 'completed';
    case Failed = 'failed';
}
