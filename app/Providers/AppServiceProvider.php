<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('local')) {
            config([
                'cors.paths' => ['api/*', '*'],
                'cors.allowed_origins' => ['*'],
                'cors.supports_credentials' => false,
            ]);
        } else {
            config([
                'cors.paths' => ['api/*', '*'],
                'cors.allowed_origins' => config('karnacab.cors_origins'),
                'cors.supports_credentials' => true,
            ]);
        }
    }
}
