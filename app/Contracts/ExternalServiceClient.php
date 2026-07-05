<?php

namespace App\Contracts;

use App\Models\ServiceVersion;
use App\Support\External\ExternalResult;

/**
 * Talks to a service's external provider over its version's post_url / get_url.
 * The HTTP implementation is used everywhere; tests fake the HTTP layer. Phase 4
 * adds webhook ingestion alongside polling.
 */
interface ExternalServiceClient
{
    /**
     * Submit an order payload; returns the provider's external order id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submit(ServiceVersion $version, array $payload): string;

    /**
     * Poll for an external order's results. Returns null while still pending.
     */
    public function poll(ServiceVersion $version, string $externalOrderId): ?ExternalResult;
}
