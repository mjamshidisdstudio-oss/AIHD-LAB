<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Which phase of an external attempt failed: the initial POST, the response
 * timeout window, or an explicit error reported by the service.
 */
enum FailureStage: string
{
    use HasValues;

    case Post = 'post';
    case Timeout = 'timeout';
    case Service = 'service';
}
