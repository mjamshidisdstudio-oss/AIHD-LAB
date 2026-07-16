<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private channels are authorized here. Order progress is streamed to the
| user who owns the order (identified by the opaque "user_ref"), so the
| socket only receives updates for orders it actually placed.
|
| $resolvedUserRef is NOT an Eloquent user — end-customer identity is a
| core-issued bearer token, never a Laravel session/Sanctum user. It arrives
| as a plain string because Marketplace\BroadcastAuthController resolves the
| bearer token via the same auth.core middleware every other site-order
| endpoint uses, then calls $request->setUserResolver() before delegating to
| Broadcast::auth() — Laravel's own channel-authorization plumbing always
| reads the "user" via $request->user(), which has no notion of our token.
|
*/

Broadcast::channel('orders.{userRef}', function (?string $resolvedUserRef, string $userRef) {
    return $resolvedUserRef !== null && $resolvedUserRef === $userRef;
});
