<?php

return [

    'enabled' => env('LOG_VIEWER_ENABLED', true),

    'api_only' => env('LOG_VIEWER_API_ONLY', false),

    'require_auth_in_production' => true,

    'route_domain' => env('LOG_VIEWER_ROUTE_DOMAIN'),

    'route_path' => 'log-viewer',

    'assets_path' => 'vendor/log-viewer',

    'back_to_system_url' => 'https://admin.revivoto.ai/logs',

    'back_to_system_label' => 'Back to admin',

    'timezone' => null,

    'datetime_format' => 'Y-m-d H:i:s',

    'middleware' => [
        'web',
        \App\Http\Middleware\AuthenticateAdminForLogViewer::class,
        'Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer',
    ],

    'api_middleware' => [
        'web',
        \App\Http\Middleware\AuthenticateAdminForLogViewer::class,
        'Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer',
    ],

    'api_stateful_domains' => env('LOG_VIEWER_API_STATEFUL_DOMAINS')
        ? array_map('trim', explode(',', env('LOG_VIEWER_API_STATEFUL_DOMAINS')))
        : null,

    'hosts' => [
        'local' => [
            'name' => ucfirst(env('APP_ENV', 'local')),
        ],
    ],

    'include_files' => [
        '*.log',
        '**/*.log',
    ],

    'exclude_files' => [],

    'hide_unknown_files' => true,

    'shorter_stack_trace_excludes' => [
        '/vendor/symfony/',
        '/vendor/laravel/framework/',
        '/vendor/barryvdh/laravel-debugbar/',
    ],

    'cache_driver' => env('LOG_VIEWER_CACHE_DRIVER', null),

    'cache_key_prefix' => 'lv',

    'lazy_scan_chunk_size_in_mb' => 50,

    'strip_extracted_context' => true,

    'per_page_options' => [10, 25, 50, 100, 250, 500],

    'defaults' => [
        'use_local_storage' => true,
        'folder_sorting_method' => 'ModifiedTime',
        'folder_sorting_order' => 'desc',
        'file_sorting_method' => 'ModifiedTime',
        'log_sorting_order' => 'desc',
        'per_page' => 25,
        'theme' => 'Dark',
        'shorter_stack_traces' => false,
    ],

    'exclude_ip_from_identifiers' => env('LOG_VIEWER_EXCLUDE_IP_FROM_IDENTIFIERS', false),

    'root_folder_prefix' => 'root',
];
