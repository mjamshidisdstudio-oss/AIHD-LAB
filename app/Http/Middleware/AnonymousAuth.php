<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for AuthenticateWithCoreToken when config('lab.auth_mode') is
 * 'anonymous' (Phase L2 launch mode, no core-team login available yet) --
 * there is no token to verify, so instead this resolves a stable per-browser
 * identity from a signed cookie, generating one on first visit. Everything
 * downstream reads $request->userRef() exactly as it does with a real core
 * token, so orders/votes/bookmarks all attribute per visitor unchanged.
 *
 * The cookie is integrity-checked with CookieValuePrefix -- the same
 * lightweight HMAC-prefix primitive Illuminate\Cookie\Middleware\
 * EncryptCookies uses -- rather than full encryption, since an anonymous id
 * has nothing confidential to hide, only tampering to detect. This
 * middleware manages the cookie itself end to end (read on the request,
 * attach directly to the response) rather than depending on the 'api'
 * middleware group carrying EncryptCookies/AddQueuedCookiesToResponse, which
 * it does not by default. A missing or tampered cookie is simply treated as
 * a new visitor -- there is nothing to reject here the way a wrong core
 * token would be.
 */
class AnonymousAuth
{
    private const COOKIE_NAME = 'aihd_anon_id';

    public function handle(Request $request, Closure $next): Response
    {
        $userRef = $this->resolveFromCookie($request);
        $isNew = $userRef === null;
        $userRef ??= 'anon-'.(string) Str::uuid();

        $request->attributes->set('user_ref', $userRef);

        $response = $next($request);

        if ($isNew) {
            $signed = CookieValuePrefix::create(self::COOKIE_NAME, config('app.key')).$userRef;
            $response->headers->setCookie(Cookie::make(
                self::COOKIE_NAME,
                $signed,
                60 * 24 * 365, // ~1 year: a stable identity, not a session.
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        return $response;
    }

    private function resolveFromCookie(Request $request): ?string
    {
        $raw = $request->cookie(self::COOKIE_NAME);
        if ($raw === null) {
            return null;
        }

        return CookieValuePrefix::validate(self::COOKIE_NAME, $raw, [config('app.key')]);
    }
}
