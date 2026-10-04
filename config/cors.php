<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // Exact HTTP(S) origins only; wildcard origins cannot be used with cookies.
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS',
            in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
                ? 'http://localhost:5173,http://localhost:5174'
                : ''
        ))),
        static fn (string $origin): bool => preg_match(
            '~^https?://[a-z0-9.-]+(?::[0-9]+)?$~iD', $origin
        ) === 1,
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
