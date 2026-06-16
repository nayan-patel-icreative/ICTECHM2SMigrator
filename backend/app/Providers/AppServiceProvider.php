<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('api', function (Request $request) {
            $shop = (string) $request->attributes->get('shop_domain', '');
            if ($shop !== '') {
                return Limit::perMinute(120)->by($shop);
            }

            return Limit::perMinute(60)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('migration', function (Request $request) {
            $shop = (string) $request->attributes->get('shop_domain', '');
            if ($shop !== '') {
                return Limit::perMinute(600)->by($shop);
            }

            return Limit::perMinute(300)->by($request->ip() ?? 'unknown');
        });
    }
}
