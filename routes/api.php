<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletFundingController;
use App\Http\Controllers\API\PaystackWebhookController;

// Email verification routes
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');

// Resend verification email
Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail'])
    ->middleware('throttle:6,1');

// Transaction recipient resolution (requires authentication)
Route::post('/resolve-recipient', [TransactionController::class, 'resolve'])
    ->middleware('auth:sanctum');


// Auth routes (no authentication required)
Route::prefix('auth')
    ->middleware(['web', 'throttle:6,1'])
    ->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

// Logout route (requires session authentication)    
Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware(['web', 'auth:web']);

// Paystack webhook 
Route::post('/webhook/paystack', [PaystackWebhookController::class, 'handle']);

// Protected routes (requires Sanctum authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Get authenticated user
    Route::get('/user', function (Request $request) {
        return response()->json(['user' => $request->user()]);
    });
    // Wallets
    Route::get('/wallets', [WalletController::class, 'show']);
    // Transactions
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::post('/', [TransactionController::class, 'store']);
        Route::post('/transfer', [TransactionController::class, 'transfer']);
    });
        //Paystack wallet funding route
        Route::post('/wallet/fund/initiate', [WalletFundingController::class, 'initiate']);
        Route::post('/wallet/fund/verify', [WalletFundingController::class, 'verify']);
});

