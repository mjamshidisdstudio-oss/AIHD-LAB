<?php

namespace Tests\Concerns;

use App\Contracts\TokenAuthenticator;
use App\Services\Auth\MockTokenAuthenticator;
use Illuminate\Support\Str;

/**
 * Site-order requests authenticate via a core-issued bearer token, not
 * Sanctum — end-customer identity is owned by the core team. This registers a
 * token against the fast in-memory MockTokenAuthenticator (swapping out the
 * real HTTP CoreTokenAuthenticator for tests that don't care about its wire
 * format) and returns the header to send.
 */
trait ActsAsCoreUser
{
    /**
     * @return array<string, string>
     */
    protected function coreUserHeaders(string $userRef = 'user-1'): array
    {
        $token = (string) Str::uuid();

        $this->app->instance(TokenAuthenticator::class, new MockTokenAuthenticator);
        MockTokenAuthenticator::registerToken($token, $userRef);

        return ['Authorization' => "Bearer {$token}"];
    }
}
