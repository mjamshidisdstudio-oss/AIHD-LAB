<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 'auth.core' alias resolves to THIS class, always -- never directly to
 * AuthenticateWithCoreToken or AnonymousAuth. Both bootstrap/app.php's alias
 * assignment and its prependToPriorityList() call run inside a callback fired
 * via afterResolving(Kernel::class), which happens the moment the HTTP kernel
 * is first resolved out of the container -- for a real request that is
 * BEFORE the LoadConfiguration bootstrapper has run, so a config() call
 * there would fail with "Target class [config] does not exist". Deciding
 * which real middleware to run in here instead, at request-handling time
 * (well after config is loaded either way), sidesteps that entirely and
 * additionally makes LAB_AUTH_MODE genuinely flippable via a live config()
 * call, not just at boot.
 */
class SiteAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $middlewareClass = config('lab.auth_mode', 'core') === 'anonymous'
            ? AnonymousAuth::class
            : AuthenticateWithCoreToken::class;

        return app($middlewareClass)->handle($request, $next);
    }
}
