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
    case InvalidMediaReference = 'invalid_media_reference';
    case FailureReported = 'failure_reported';

    /**
     * A POST /api/storage upload was rejected by the media validation policy
     * (config/media.php) -- not an inbound webhook delivery, but logged to the
     * same receipt trail (see StorageController).
     */
    case InvalidMedia = 'invalid_media';
}
