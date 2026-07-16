<?php

namespace App\Services\Auth;

use App\Contracts\TokenAuthenticator;
use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Core\CoreServiceUnavailableException;
use App\Support\Core\CoreApiClient;

/**
 * Resolves a site user's bearer token via the core team's identity endpoint.
 * Bound as the default TokenAuthenticator (see DomainServiceProvider).
 */
class CoreTokenAuthenticator implements TokenAuthenticator
{
    public function __construct(private readonly CoreApiClient $client) {}

    public function authenticate(string $token): string
    {
        $response = $this->client->post('/verify-token', [
            'token' => $token,
        ], operation: 'verify-token');

        if ($response->status() === 401) {
            throw InvalidTokenException::rejected();
        }

        if ($response->failed()) {
            throw CoreServiceUnavailableException::unreachable('verify-token');
        }

        return (string) $response->json('user_ref');
    }
}
