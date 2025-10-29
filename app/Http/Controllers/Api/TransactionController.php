<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Store a new transaction.
     */
    public function store(StoreTransactionRequest $request)
    {
        $data = $request->validated();

        // Check for duplicate (idempotent) requests
        $existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json([
                'message' => 'Duplicate request (idempotent)',
                'transaction' => $existing,
                'wallet_balance' => $existing->wallet->balance ?? null
            ], 200);
        }

        $wallet = Wallet::findOrFail($data['wallet_id']);

        // Prevent overdraft
        if ($data['type'] === 'debit' && $wallet->balance < $data['amount']) {
            return response()->json(['message' => 'Insufficient balance'], 422);
        }

        // Use database transaction for safety
        $transaction = DB::transaction(function () use ($data, $wallet) {
            $wallet->balance += ($data['type'] === 'credit')
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

        return response()->json([
            'message' => 'Transaction successful',
            'transaction' => $transaction,
            'wallet_balance' => $wallet->balance,
        ]);
    }

    /**
     * Get a paginated list of transactions.
     */
    public function index(Request $request)
    {
        $transactions = Transaction::query();

        // Filters
        if ($request->filled('q')) {
            $transactions->where('reference', 'like', '%' . $request->q . '%');
        }

        if ($request->filled('type')) {
            $transactions->where('type', $request->type);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $transactions->whereBetween('created_at', [$request->from, $request->to]);
        }

        // Paginate
        $paginated = $transactions->orderByDesc('created_at')->paginate(
            $request->get('per_page', 10)
        );

        // Summary (fix: match your 'credit'/'debit' types)
        $summary = [
            'total_in' => Transaction::where('type', 'credit')->sum('amount'),
            'total_out' => Transaction::where('type', 'debit')->sum('amount'),
        ];

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total' => $paginated->total(),
                'page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
            ],
            'summary' => $summary,
        ]);
    }
}
