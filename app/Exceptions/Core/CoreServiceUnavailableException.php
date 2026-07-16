<?php

namespace App\Exceptions\Core;

use RuntimeException;
use Throwable;

/**
 * Raised when the core identity/coin service cannot be reached (connection
 * failure, timeout, or an unexpected server error) rather than a domain
 * rejection (401/402/404). Rendered as HTTP 503 — the caller should retry
 * later; we must never charge or authenticate on a guess.
 */
class CoreServiceUnavailableException extends RuntimeException
{
    public int $status = 503;

    public static function unreachable(string $operation, ?Throwable $previous = null): self
    {
        return new self("The core service is unreachable while attempting: {$operation}.", previous: $previous);
    }
}
