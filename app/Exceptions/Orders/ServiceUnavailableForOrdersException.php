<?php

namespace App\Exceptions\Orders;

use App\Models\Service;
use RuntimeException;

/**
 * Raised when an order is submitted for a service that has no published version
 * to run. Rendered as HTTP 409 (Conflict).
 */
class ServiceUnavailableForOrdersException extends RuntimeException
{
    public int $status = 409;

    public static function for(Service $service): self
    {
        return new self("Service {$service->slug} has no published version to run.");
    }
}
