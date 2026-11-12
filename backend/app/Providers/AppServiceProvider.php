<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        // Coolify terminates TLS at its reverse proxy. Some forwarded requests
        // can reach Apache as HTTP, so generated absolute routes would become
        // http:// and be blocked as mixed content on the HTTPS website.
        // Only force the public scheme when this is the configured production URL.
        if (app()->environment('production')
            && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
