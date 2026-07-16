<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Bridges core-token identity into Laravel's broadcasting auth flow. Every
 * other site-order endpoint proves identity via the auth.core middleware and
 * $request->userRef() — but Laravel's own channel-authorization plumbing
 * (Broadcaster::retrieveUser(), used by the auto-registered
 * POST /broadcasting/auth route) only ever reads $request->user(), which has
 * no concept of our bearer token. Setting a user resolver here — right before
 * delegating to Broadcast::auth() — lets routes/channels.php's
 * Broadcast::channel('orders.{userRef}', ...) callback receive the real
 * user_ref without Laravel needing a session, guard, or Sanctum user.
 */
class BroadcastAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        $request->setUserResolver(fn () => (string) $request->userRef());

        return Broadcast::auth($request);
    }
}
