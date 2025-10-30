<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function credit(Wallet $wallet, float $amount)
    {
        return DB::transaction(function () use ($wallet, $amount) {
            $wallet->balance += $amount;
            $wallet->save();

            $transaction = Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'reference' => Str::uuid(),
            ]);

            return [
                'transaction' => $transaction,
                'balance' => $wallet->balance
            ];
        });
    }

    public function debit(Wallet $wallet, float $amount)
    {
        if ($wallet->balance < $amount) {
            throw new \Exception('Insufficient balance');
        }

        return DB::transaction(function () use ($wallet, $amount) {
            $wallet->balance -= $amount;
            $wallet->save();

            $transaction = Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount' => $amount,
                'reference' => Str::uuid(),
            ]);

            return [
                'transaction' => $transaction,
                'balance' => $wallet->balance
            ];
        });
    }

    public function transactions(Wallet $wallet)
    {
        return $wallet->transactions()->latest()->get();
    }
}
