<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\StoreTransactionRequest;

use Exception;

class WalletController extends Controller
{
    protected WalletService $walletService;
    protected TransactionService $transactionService;

    public function __construct(
        WalletService $walletService,
        TransactionService $transactionService
    ) {
        $this->walletService = $walletService;
        $this->transactionService = $transactionService;
    }


    public function sendMoney(Request $request)
{
    // Don't use StoreTransactionRequest here, validate directly
    $validated = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'recipient_address' => 'required|string|exists:wallets,address', // FIXED: wallets not wallet
        'idempotency_key' => 'required|string|max:100',
        'description' => 'nullable|string|max:255'
    ]);

    try {
        // Get sender's wallet (authenticated user)
        $senderWallet = Wallet::where('user_id', $request->user()->id)->firstOrFail();

        // Prepare data for walletService
        $data = [
            'wallet_id' => $senderWallet->id,
            'amount' => $validated['amount'],
            'recipient_address' => $validated['recipient_address'], // FIXED: correct field name
            'description' => $validated['description'] ?? null,
            'idempotency_key' => $validated['idempotency_key'],
        ];

        // Process the transfer
        $result = $this->walletService->sendMoney($data);

        if ($result['status'] === 'error') {
            return response()->json($result, 422);
        }

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'debit_transaction' => $result['transaction'] ?? null,
            'credit_transaction' => $result['recipient_transaction'] ?? null,
            'sender_balance' => $result['wallet_balance'] ?? null,
        ], 200);

    } catch (Exception $e) {
        Log::error('Send money failed', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 422);
    }
}

/**
 * Transfer money between wallets using addresses
 */
public function transfer(StoreTransactionRequest $request)
{
    $validated = $request->validate([
        'recipient_address' => 'required|string|exists:wallets,address',
        'amount' => 'required|numeric|min:0.01',
        'description' => 'nullable|string|max:255',
        'idempotency_key' => 'required|string|max:100',
    ]);

    try {
        // Get authenticated user's wallet
        $senderWallet = Wallet::where('user_id', $request->user()->id)->firstOrFail();

        // Perform transfer using wallet addresses
        $result = $this->walletService->transferByAddress(
            $senderWallet->address,
            $validated['recipient_address'],
            $validated['amount'],
            $validated['idempotency_key'],
            $validated['description'] ?? null
        );

        // Handle duplicate transaction
        if ($result['status'] === 'duplicate') {
            return response()->json([
                'status' => 'duplicate',
                'message' => $result['message'],
                'transaction' => $result['transaction'],
            ], 200);
        }

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'debit_transaction' => $result['debit'],
            'credit_transaction' => $result['credit'],
            'sender_balance' => $result['sender_balance'],
            'recipient_balance' => $result['recipient_balance'],
        ], 200);

    } catch (Exception $e) {
        Log::error('Transfer failed', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 422);
    }
}

    /**
     * Credit wallet securely
     */
    public function credit(Request $request, $walletAddress)
    {
        $validated = $request->validate(rules: [
            'amount' => 'required|numeric|min:1',
            'idempotency_key' => 'required|string|max:100',
            'description' => 'nullable|string|max:255'
        ]);

        //find by wallet address
        $wallet = Wallet::where('address', $walletAddress)->firstorFail();

        try {
            $result = $this->transactionService->process([
                'wallet_address' => $wallet->address,
                'type' => 'credit',
                'amount' => $validated['amount'],
                'description' => $validated['description'] ?? null,
                'idempotency_key' => $validated['idempotency_key'],
            ]);

            return response()->json($result, 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Debit wallet securely
     */
    public function debit(Request $request, $walletId)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'recipient_address' => 'required|string',
            'idempotency_key' => 'required|string|max:100',
            'description' => 'nullable|string|max:255'
        ]);

        $senderWallet = Wallet::findOrFail($walletId);

        // Prevent sending to own wallet
        if ($validated['recipient_address'] === $senderWallet->address) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot send money to your own wallet.'
            ], 422);
        };

        // Find receiver wallet
        $recipientWallet = Wallet::where('address', $validated['recipient_address'])->first();

        if (!$recipientWallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Recipient wallet not found.'
            ], 404);
        }

            try {
            $result = $this->transactionService->process([
                'wallet_id' => $senderWallet->id,
                'type' => 'debit',
                'amount' => $validated['amount'],
                'recipient_wallet_address' => $recipientWallet->address,
                'description' => $validated['description'] ?? null,
                'idempotency_key' => $validated['idempotency_key'],
            ]);

            return response()->json($result, 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get all wallet transactions
     */
    public function transactions(Request $request)
    {
        try {
            $wallet = Wallet::where('user_id', $request->user()->id)->firstOrFail();

            $filters = [
                'wallet_id' => $wallet->id,
                'type' => $request->type ?? null,
                'status' => $request->status ?? null,
                'start_date' => $request->start_date ?? null,
                'end_date' => $request->end_date ?? null,
            ];

            $result = $this->transactionService->getTransactions(
                array_filter($filters),
                $request->per_page ?? 20
            );

            return response()->json([
                'status' => 'success',
                'data' => $result['data'],
                'meta' => $result['meta'],
                'wallet' => [
                    'id' => $wallet->id,
                    'address' => $wallet->address,
                    'balance' => $wallet->fresh()->balance,
                    'currency' => $wallet->currency ?? 'NGN',
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch transactions', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load transactions'
            ], 500);
        }
    }

     public function show(Request $request)
    {
        try {
            $wallet = Wallet::where('user_id', $request->user()->id)->firstOrFail();
            
            return response()->json([
                'wallet' => $wallet
            ], 200);
            
        } catch (Exception $e) {
            Log::error('Failed to fetch wallet', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Wallet not found'
            ], 404);
        }
    }

}
