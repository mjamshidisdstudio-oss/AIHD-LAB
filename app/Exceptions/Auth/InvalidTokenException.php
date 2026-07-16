<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * Raised when a bearer token is missing or the core identity service rejects
 * it. Rendered as HTTP 401 for the API.
 */
class InvalidTokenException extends RuntimeException
{
    public int $status = 401;

    public static function missing(): self
    {
        return new self('A bearer token is required.');
    }

    public static function rejected(): self
    {
        return new self('The bearer token was rejected.');
    }
}
