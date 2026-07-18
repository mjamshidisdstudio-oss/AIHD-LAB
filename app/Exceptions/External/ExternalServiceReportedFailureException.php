<?php

namespace App\Exceptions\External;

use RuntimeException;

/**
 * Raised when the external provider explicitly reports that an order failed
 * (poll status=failed, or an equivalent webhook), as opposed to merely being
 * unreachable (ConnectionException) or never finishing (timeout). Distinct
 * from those so FailRequest can record FailureStage::Service rather than
 * Post/Timeout.
 */
class ExternalServiceReportedFailureException extends RuntimeException
{
    public static function reported(?string $reason): self
    {
        return new self($reason ?? 'The external service reported a failure.');
    }
}
