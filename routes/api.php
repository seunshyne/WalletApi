<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AuthController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::middleware('auth:sanctum')->get('/transactions', [TransactionController::class, 'index']);

Route::middleware(['auth:sanctum', 'idempotent'])->post('/transactions', [TransactionController::class, 'store']);


Route::post('/wallets/{wallet}/credit', [WalletController::class, 'credit']);
Route::post('/wallets/{wallet}/debit', [WalletController::class, 'debit']);
Route::get('/wallets/{wallet}/transactions', [WalletController::class, 'transactions']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::prefix('transactions')->group(function () {
    Route::get('/', [TransactionController::class, 'index']);
    Route::post('/', [TransactionController::class, 'store'])->middleware('idempotent');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/transactions', [TransactionController::class, 'index']);
});

Route::post('/', [TransactionController::class, 'store'])->middleware('idempotent');


