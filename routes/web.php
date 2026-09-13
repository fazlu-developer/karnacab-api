<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return [
        'ok' => true,
        'service' => 'karnacab-api',
        'runtime' => 'laravel12',
        'health' => '/api/v1/health',
        'cms' => '/api/v1/cms/site',
    ];
});

Route::get('/storage/{path}', function (string $path) {
    abort_if(str_contains($path, '..'), 404);
    $full = storage_path('app/public/'.ltrim($path, '/'));
    abort_unless(is_file($full), 404);

    return response()->file($full, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');
