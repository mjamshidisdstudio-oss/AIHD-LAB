<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Lifecycle state of a service. "auto_disabled" is set by the platform when
 * consecutive_failures crosses its threshold, distinct from an operator pause.
 */
enum ServiceStatus: string
{
    use HasValues;

    case Active = 'active';
    case Paused = 'paused';
    case AutoDisabled = 'auto_disabled';
}
