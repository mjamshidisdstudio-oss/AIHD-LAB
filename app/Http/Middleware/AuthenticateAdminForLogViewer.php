<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Viewer is served from Laravel web routes, but the admin panel uses
 * Sanctum bearer tokens (Nuxt SPA). Accept Authorization: Bearer or a
 * one-time ?token= handoff from the admin UI, validate the PAT, start a
 * web session for subsequent Log Viewer API calls, then strip the token
 * from the URL.
 */
class AuthenticateAdminForLogViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $plainToken = $request->bearerToken() ?? $request->query('token');
            if (is_string($plainToken) && $plainToken !== '') {
                $accessToken = PersonalAccessToken::findToken($plainToken);
                $user = $accessToken?->tokenable;
                if ($user instanceof User && $user->is_admin) {
                    Auth::login($user);
                    if ($request->query('token')) {
                        return redirect()->to($request->url());
                    }
                }
            }
        }

        $user = Auth::user();
        if (! $user instanceof User || ! $user->is_admin) {
            abort(403, 'Admin access required.');
        }

        return $next($request);
    }
}
