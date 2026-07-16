<?php

namespace App\Services\Auth;

use App\Contracts\TokenAuthenticator;
use App\Exceptions\Auth\InvalidTokenException;
use Illuminate\Support\Facades\Cache;

/**
 * A fast in-memory double for tests that want to isolate behavior from the
 * real HTTP CoreTokenAuthenticator. Tokens are registered explicitly; anything
 * unregistered is rejected, same as a real 401.
 */
class MockTokenAuthenticator implements TokenAuthenticator
{
    public static function registerToken(string $token, string $userRef): void
    {
        Cache::forever(self::key($token), $userRef);
    }

    public function authenticate(string $token): string
    {
        $userRef = Cache::get(self::key($token));

        if ($userRef === null) {
            throw InvalidTokenException::rejected();
        }

        return $userRef;
    }

    private static function key(string $token): string
    {
        return "mock-token-auth:{$token}";
    }
}
