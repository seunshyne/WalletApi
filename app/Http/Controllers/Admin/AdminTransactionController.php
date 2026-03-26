<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminTransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;

class AdminTransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = Transaction::with('wallet.user')
            ->when($request->search, fn($q) =>
                $q->where('reference', 'like', "%{$request->search}%")
            )
            ->when($request->status, fn($q) =>
                $q->where('status', $request->status)
            )
            ->when($request->type, fn($q) =>
                $q->where('type', $request->type)
            )
            ->when($request->date_from, fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from)
            )
            ->when($request->date_to, fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to)
            )
            ->latest()
            ->paginate(20);

        return AdminTransactionResource::collection($transactions);
    }

    public function show(Transaction $transaction)
    {
        $transaction->load('wallet.user');
        return new AdminTransactionResource($transaction);
    }

    public function flag(Transaction $transaction)
    {
         if ($transaction->flagged) {
        return response()->json(['message' => 'Already flagged'], 422);
    }

        $transaction->update(['flagged' => true]);
        return response()->json(['message' => 'Transaction flagged for review']);
    }
}
