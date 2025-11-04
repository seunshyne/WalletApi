<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Transaction;

class IdempotencyMiddleware
{
    public function handle($request, Closure $next)
    {
        if ($request->has('idempotency_key')) {
            $existing = Transaction::where('idempotency_key', $request->idempotency_key)->first();
            if ($existing) {
                return response()->json([
                    'message' => 'Duplicate request (idempotent)',
                    'transaction' => $existing,
                    'wallet_balance' => $existing->wallet->balance ?? null
                ]);
            }
        }

        return $next($request);
    }
}
