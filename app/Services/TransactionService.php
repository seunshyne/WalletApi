<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * Handle credit/debit transactions with idempotency and balance safety.
     */
    public function process(array $data)
    {
        // 1️⃣ Check for existing idempotent transaction
        $existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return [
                'status' => 'duplicate',
                'transaction' => $existing,
                'wallet_balance' => $existing->wallet->balance ?? null
            ];
        }

        // 2️⃣ Find wallet
        $wallet = Wallet::findOrFail($data['wallet_id']);

        // 3️⃣ Overdraft protection
        if ($data['type'] === 'debit' && $wallet->balance < $data['amount']) {
            throw new \Exception('Insufficient balance', 422);
        }

        // 4️⃣ Process safely inside DB transaction
        $transaction = DB::transaction(function () use ($data, $wallet) {
            $wallet->balance += $data['type'] === 'credit'
                ? $data['amount']
                : -$data['amount'];
            $wallet->save();

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'reference' => $data['reference'],
                'idempotency_key' => $data['idempotency_key'],
            ]);
        });

        return [
            'status' => 'success',
            'transaction' => $transaction,
            'wallet_balance' => $wallet->balance,
        ];
    }

    /**
     * Fetch transactions with filters, pagination, and summary.
     */
    public function getTransactions($request)
    {
        $transactions = Transaction::query();

        if ($request->filled('q')) {
            $transactions->where('reference', 'like', '%' . $request->q . '%');
        }

        if ($request->filled('type')) {
            $transactions->where('type', $request->type);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $transactions->whereBetween('created_at', [$request->from, $request->to]);
        }

        $paginated = $transactions->orderByDesc('created_at')->paginate(
            $request->get('per_page', 10)
        );

        $summary = [
            'total_in' => Transaction::where('type', 'credit')->sum('amount'),
            'total_out' => Transaction::where('type', 'debit')->sum('amount'),
        ];

        return [
            'data' => $paginated->items(),
            'meta' => [
                'total' => $paginated->total(),
                'page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
            ],
            'summary' => $summary
        ];
    }
}
