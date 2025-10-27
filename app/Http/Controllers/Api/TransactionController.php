<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // ✅ Validation rules
        $data = $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|unique:transactions,reference',
            'idempotency_key' => 'required|string|unique:transactions,idempotency_key',
        ]);

        $wallet = Wallet::findOrFail($data['wallet_id']);

        // ✅ Idempotency check (if repeated, return previous result)
        $existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json([
                'message' => 'Duplicate request (idempotent)',
                'transaction' => $existing,
                'wallet_balance' => $wallet->balance
            ]);
        }

        // ✅ Overdraft protection
        if ($data['type'] === 'debit' && $wallet->balance < $data['amount']) {
            return response()->json(['message' => 'Insufficient balance'], 422);
        }

        // ✅ Use DB transaction for safety
        $transaction = DB::transaction(function () use ($data, $wallet) {
            if ($data['type'] === 'credit') {
                $wallet->balance += $data['amount'];
            } else {
                $wallet->balance -= $data['amount'];
            }
            $wallet->save();

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'reference' => $data['reference'],
                'idempotency_key' => $data['idempotency_key'],
            ]);
        });

        return response()->json([
            'message' => 'Transaction successful',
            'transaction' => $transaction,
            'wallet_balance' => $wallet->balance
        ]);
    }
}
