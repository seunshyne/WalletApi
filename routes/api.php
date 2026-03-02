<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AuthController;

// Email verification (signed URL, no session needed)
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');

// Resend verification (no session needed)
Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail'])
    ->middleware('throttle:6,1');

// All routes that need session (login, logout, protected routes)
Route::middleware(['web'])->group(function () {

    // Auth routes (no authentication required)
    Route::prefix('auth')->middleware('throttle:6,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Protected routes (requires authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/resolve-recipient', [TransactionController::class, 'resolve']);

        Route::get('/user', function (Request $request) {
            return response()->json(['user' => $request->user()]);
        });

        Route::get('/wallets', [WalletController::class, 'show']);

        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'index']);
            Route::post('/', [TransactionController::class, 'store']);
            Route::post('/transfer', [TransactionController::class, 'transfer']);
        });
    });
});