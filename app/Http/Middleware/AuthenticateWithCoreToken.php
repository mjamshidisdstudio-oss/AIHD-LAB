<?php

namespace App\Http\Middleware;

use App\Contracts\TokenAuthenticator;
use App\Exceptions\Auth\InvalidTokenException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates end-customer requests via the core identity service, NOT
 * Sanctum — our own `users` table is admin-only. A missing bearer token is
 * rejected without ever calling the core (no wasted call, no coin call can
 * follow). On success, the resolved user_ref is attached to the request for
 * downstream code to read via $request->userRef().
 */
class AuthenticateWithCoreToken
{
    public function __construct(private readonly TokenAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            throw InvalidTokenException::missing();
        }

        $userRef = $this->authenticator->authenticate($token);

        $request->attributes->set('user_ref', $userRef);

        return $next($request);
    }
}
