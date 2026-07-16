<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Core Service (identity + coins)
    |--------------------------------------------------------------------------
    |
    | The core team owns end-customer identity and the coin wallet; we talk to
    | it over HTTP with a service credential. Phase 5's "single-point swap":
    | when the real endpoints are available, only base_url/credential change —
    | no code. Locally/in tests this points at the in-app LocalCoreStub
    | (routes/core-stub.php, mounted outside production).
    |
    */

    'base_url' => env('CORE_BASE_URL', env('APP_URL', 'http://localhost').'/dev/core'),

    'credential' => env('CORE_SERVICE_CREDENTIAL', 'dev-service-credential'),

    'timeout_seconds' => (int) env('CORE_TIMEOUT_SECONDS', 5),

    'connect_timeout_seconds' => (int) env('CORE_CONNECT_TIMEOUT_SECONDS', 3),

    /*
    |--------------------------------------------------------------------------
    | LocalCoreStub (non-production only)
    |--------------------------------------------------------------------------
    */

    'stub' => [
        // A fixed token accepted for manual/local testing without pre-registering one.
        'dev_token' => env('CORE_STUB_DEV_TOKEN', 'dev-token'),
        'dev_user_ref' => env('CORE_STUB_DEV_USER_REF', 'dev-user'),
        'seed_balance' => (int) env('CORE_STUB_SEED_BALANCE', 1000),
    ],

];
