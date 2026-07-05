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
*/

Broadcast::channel('orders.{userRef}', function ($user, string $userRef) {
    return (string) $user->id === (string) $userRef;
});
