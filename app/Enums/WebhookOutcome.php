<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The classified result of processing an inbound webhook delivery. Every
 * delivery is logged with exactly one outcome for auditing and debugging.
 */
enum WebhookOutcome: string
{
    use HasValues;

    case Ingested = 'ingested';
    case Duplicate = 'duplicate';
    case InvalidSignature = 'invalid_signature';
    case UnknownOrder = 'unknown_order';
    case StaleAttempt = 'stale_attempt';
    case ValidationError = 'validation_error';
}
