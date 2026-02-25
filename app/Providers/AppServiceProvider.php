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
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');
            return [
                Limit::perMinute(5)->by($request->ip().'|'.$email),
            ];
        });

        RateLimiter::for('transfers', function (Request $request) {
            $userId = $request->user()?->id;
            return [
                Limit::perMinute(20)->by((string) ($userId ?: $request->ip())),
            ];
        });
    }
}
