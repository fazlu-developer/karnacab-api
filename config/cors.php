<?php

$local = env('APP_ENV', 'production') === 'local';
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env(
    'CORS_ORIGINS',
    'http://localhost:8000,https://karnaride.jodoocorp.in,https://www.karnaride.jodoocorp.in,https://admin.karnaride.in',
)))));

return [
    'paths' => ['api/*', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $local ? ['*'] : $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => ! $local,
];
