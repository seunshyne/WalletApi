<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransactionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::post('/transactions', [TransactionController::class, 'store']);


Route::post('/wallets/{wallet}/credit', [WalletController::class, 'credit']);
Route::post('/wallets/{wallet}/debit', [WalletController::class, 'debit']);
Route::get('/wallets/{wallet}/transactions', [WalletController::class, 'transactions']);

