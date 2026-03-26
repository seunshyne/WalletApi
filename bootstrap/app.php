<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {                          // ✅ correct way
            Route::middleware('api')
                ->prefix('api/admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )

    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotent' => App\Http\Middleware\IdempotencyMiddleware::class,
            'csrf.specific' => App\Http\Middleware\VerifySpecificCsrfToken::class,
            'admin' => App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/auth/register',
            'api/auth/login',
            'api/admin/*',
            'api/webhook/paystack',
        ]);

        $middleware->trustProxies(at: '*');

        // Allows Laravel to handle SPA cookie auth on API routes
        $middleware->statefulApi();
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
