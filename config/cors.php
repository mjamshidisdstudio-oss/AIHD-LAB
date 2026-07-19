<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The marketplace/admin Nuxt apps and this API are served from different
    | origins (different ports in dev/acceptance, plausibly different
    | subdomains in production) -- fine for the core-token bearer path, which
    | never relies on cookies. Phase L2's anonymous-visitor identity does: it
    | is a signed cookie, so the browser must actually be allowed to carry it
    | cross-origin. Wildcard origins ('*', the framework default) cannot be
    | combined with credentials per the CORS spec -- browsers reject it -- so
    | allowed_origins must be an explicit list, and supports_credentials must
    | be true.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://127.0.0.1:3100,http://127.0.0.1:3200,http://localhost:3100,http://localhost:3200'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
