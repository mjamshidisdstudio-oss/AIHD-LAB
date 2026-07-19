<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Launch-mode flags (Phase L2)
    |--------------------------------------------------------------------------
    |
    | An independent launch, ahead of the core team's billing/identity
    | endpoints being ready, needs to run with coins and real customer auth
    | switched off -- WITHOUT deleting either. Both are flags, not code
    | removal: flipping them back is a config change, never a rebuild.
    |
    | billing_enabled: when false, CoinService resolves to NullCoinService
    | (DomainServiceProvider) -- every deduct/settle/refund is a silent no-op
    | and balance() is unlimited. The real CoreCoinService binding, and
    | MockCoinService for tests, are both untouched and still exist.
    |
    | auth_mode: 'core' (default) authenticates end-customer requests via the
    | core-issued bearer token (AuthenticateWithCoreToken), same as today.
    | 'anonymous' authenticates via a stable per-browser identity instead
    | (AnonymousAuth) -- no login, no core token, but request->userRef()
    | still resolves to a stable per-visitor value, so everything downstream
    | (orders, votes, bookmarks) keeps working unchanged. Admin Sanctum auth
    | is a completely separate guard and is never affected by this flag.
    |
    */

    'billing_enabled' => env('LAB_BILLING_ENABLED', true),

    'auth_mode' => env('LAB_AUTH_MODE', 'core'),

];
