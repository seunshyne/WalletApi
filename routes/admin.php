<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminTransactionController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use Illuminate\Support\Facades\Route;

//Public admin route
Route::post('/login', [AdminAuthController::class, 'login']);

//Protected admin routes
Route::middleware(['auth:sanctum', 'admin'])->group(function () {

    Route::post('/logout', [AdminAuthController::class, 'logout']);

    // Users
    Route::get('/users',                    [AdminUserController::class, 'index']);
    Route::get('/users/{user}',             [AdminUserController::class, 'show']);
    Route::patch('/users/{user}/suspend',   [AdminUserController::class, 'suspend']);
    Route::patch('/users/{user}/unsuspend', [AdminUserController::class, 'unsuspend']);

    // Transactions
    Route::get('/transactions',             [AdminTransactionController::class, 'index']);
    Route::get('/transactions/{transaction}',[AdminTransactionController::class, 'show']);
    Route::patch('/transactions/{transaction}/flag', [AdminTransactionController::class, 'flag']);

    // Analytics
    Route::get('/analytics/summary',        [AdminAnalyticsController::class, 'summary']);
    Route::get('/analytics/transactions',   [AdminAnalyticsController::class, 'transactions']);
    Route::get('/analytics/users',          [AdminAnalyticsController::class, 'users']);
});