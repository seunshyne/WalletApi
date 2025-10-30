<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use Illuminate\Http\Request;
use App\Services\TransactionService;
use Exception;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Store a new transaction.
     */
    public function store(StoreTransactionRequest $request)
    {
        $data = $request->validated();

        try {
            $result = $this->transactionService->process($data);

            if ($result['status'] === 'duplicate') {
                return response()->json([
                    'message' => 'Duplicate request (idempotent)',
                    'transaction' => $result['transaction'],
                    'wallet_balance' => $result['wallet_balance']
                ], 200);
            }

            return response()->json([
                'message' => 'Transaction successful',
                'transaction' => $result['transaction'],
                'wallet_balance' => $result['wallet_balance']
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get paginated transactions.
     */
    public function index(Request $request)
    {
        $result = $this->transactionService->getTransactions($request);
        return response()->json($result);
    }
}
