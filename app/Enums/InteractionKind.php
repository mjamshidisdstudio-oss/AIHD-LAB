<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Post-generation engagement events recorded for analytics: downloading a
 * result or clicking through to an external service.
 */
enum InteractionKind: string
{
    use HasValues;

    case Download = 'download';
    case ExternalClick = 'external_click';
}
