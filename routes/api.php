<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AuthController;

// Email verification routes
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed')
    ->name('verification.verify');

// Resend verification email
Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail'])
    ->middleware('throttle:6,1');

// Transaction recipient resolution(confirming wallet address or user details)
Route::post('/resolve-recipient', [TransactionController::class, 'resolve'])
    ->middleware('auth:sanctum');


// Auth routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Routes protected by Sanctum
Route::middleware('auth:sanctum')->group(function () {

    // Get authenticated user
    Route::get('/user', function (Request $request) {
        return $request->user();
    });


    // Wallets routes
    Route::get('/wallets', [WalletController::class, 'show']);

    // Logout
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});




// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth routes
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return response()->json(['user' => $request->user()]);
    });

    // Transaction routes (if you want to use TransactionController directly)
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::post('/', [TransactionController::class, 'store']);
        Route::post('/transfer', [TransactionController::class, 'transfer']);
    });
});
