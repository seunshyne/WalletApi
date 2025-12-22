<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

// Email verification routes
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed')
    ->name('verification.verify');


Route::post('/email/resend', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification email resent']);
    })->middleware(['auth:sanctum', 'throttle:6,1'])->name('verification.send');

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

    // Wallet routes
    // Route::get('/wallet', [WalletController::class, 'show']); // fetch logged-in user's wallet
    // Route::post('/wallet/{wallet}/credit', [WalletController::class, 'credit']);
    // Route::post('/wallet/{wallet}/debit', [WalletController::class, 'debit']);
    // Route::get('/wallet/{wallet}/transactions', [WalletController::class, 'transactions']);


    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'transfer'])->middleware('idempotent');

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

    // Wallet routes
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'show']);
        Route::post('/transfer', [WalletController::class, 'sendMoney']); // THIS IS THE KEY ROUTE
        Route::get('/transaction', [WalletController::class, 'transactions']);

        // Optional: Keep these for manual credit/debit
        Route::post('/{walletId}/credit', [WalletController::class, 'credit']);
        Route::post('/{walletId}/debit', [WalletController::class, 'debit']);
    });

    // Transaction routes (if you want to use TransactionController directly)
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::post('/', [TransactionController::class, 'store']);
        Route::post('/transfer', [TransactionController::class, 'transfer']);
    });
});
