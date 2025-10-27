<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;


class WalletController extends Controller
{

    public function credit(Request $request, $walletId) {
        $request->validate([
            'amount' => 'required|numeric|min:1'
        ]);

        $wallet = Wallet::findorFail($walletId);

        DB::transaction(function () use ($wallet, $request) {
            $wallet->balance += $request->amount;
            $wallet->save();

            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $request->amount,
                'reference' => Str::uuid()
            ]);
        });
        return response()->json(['message' => 'Wallet credited successfully']);
    }

    public function debit (request $request, $walletId) {
        $request->validate([
            'amount' => 'required|numeric|min:1'
        ]);

        $wallet = Wallet::findorFail($walletId);

        if ($wallet->balance < $request->amount) {
            return response()->json([['message' => 'Insufficient balance'], 422]);
        }
        
        DB::transaction( function() use($wallet, $request) {
            $wallet->balance -= $request->amount;
            $wallet->save();

            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount' => $request->amount,
                'reference' => Str::uuid()
            ]);
        });
        return response()->json(['message' => 'Wallet debited successfully', 'balance' => $wallet->balance]);

    }

    public function transaction($walletId) {
        $transactions = Transaction::where('wallet_id', $walletId)->latest()->get();
        return response()->json($transactions);
    }

}