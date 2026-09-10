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
