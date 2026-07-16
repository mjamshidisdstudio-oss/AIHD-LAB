<?php

namespace App\Contracts;

use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Core\CoreServiceUnavailableException;

/**
 * Resolves a site user's bearer token to an opaque user_ref, via the core
 * team's identity system. The core owns end-customer identity — our own
 * `users` table is for admin accounts only. The real implementation is used
 * everywhere; tests fake the HTTP layer or swap in the mock.
 */
interface TokenAuthenticator
{
    /**
     * @throws InvalidTokenException if the token is missing or rejected (401)
     * @throws CoreServiceUnavailableException if the core cannot be reached
     */
    public function authenticate(string $token): string;
}
