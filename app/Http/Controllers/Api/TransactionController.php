<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreTransactionRequest;
use Illuminate\Http\Request;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Log;
use Exception;

class TransactionController extends Controller
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

   public function index(Request $request)
{
    $user = Auth::user();

     $filters = $request->only([
            'type',
            'start_date',
            'end_date'
        ]);
        
        $perPage = (int) $request->get('per_page', 20);


    return response()->json(
            $this->transactionService->getTransactions($filters, $perPage)
        );
}

 // Fetch all transactions related to the logged-in user
    // public function index()
    // {
    //     $transactions = Transaction::with(['sender', 'senderWallet', 'recipient', 'recipientWallet'])
    //         ->where('sender_id', auth()->id())
    //         ->orWhere('recipient_id', auth()->id())
    //         ->orderBy('created_at', 'desc')
    //         ->get();

    //     return response()->json([
    //         'transactions' => $transactions->map(function($tx) {
    //             return [
    //                 'id' => $tx->id,
    //                 'amount' => $tx->amount,
    //                 'type' => $tx->type,
    //                 'created_at' => $tx->created_at->toDateTimeString(),
    //                 'sender_name' => $tx->sender->name,
    //                 'sender_address' => $tx->senderWallet->address,
    //                 'recipient_name' => $tx->recipient->name,
    //                 'recipient_address' => $tx->recipientWallet->address,
    //             ];
    //         })
    //     ]);
    // }



    public function store(StoreTransactionRequest $request)
    {
        $data = $request->validated();

        try {
            $result = $this->transactionService->process($data);

            $response = [
                'status' => $result['status'],
                'message' => $result['message'],
                'transaction' => $result['transaction'] ?? null,
                'wallet_balance' => $result['wallet_balance'] ?? null
            ];

            // Include recipient data if it exists (for transfers)
            if (isset($result['recipient_transaction'])) {
                $response['recipient_transaction'] = $result['recipient_transaction'];
                $response['recipient_wallet_balance'] = $result['recipient_wallet_balance'];
            }

            return response()->json($response, 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transfer between wallets using addresses
     */
   
   public function transfer(StoreTransactionRequest $request)
{
    Log::info('Incoming transfer request:', $request->all());

    try {
        // Get validated data
        $validated = $request->validated();

        // Call the TransactionService
        $result = $this->transactionService->transfer($validated);

        $statusCode = $result['status'] === 'success' ? 200 : ($result['code'] ?? 400);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'reference' => $result['data']['reference'] ?? null,
            'idempotency_key' => $result['data']['idempotency_key'] ?? null,
            'sender_transaction' => $result['data']['sender_transaction'] ?? null,
            'recipient_transaction' => $result['data']['recipient_transaction'] ?? null,
            'wallet_balance' => $result['data']['wallet_balance'] ?? null,
            'recipient_wallet_balance' => $result['data']['recipient_wallet_balance'] ?? null,
        ], $statusCode);

    } catch (Exception $e) {
        Log::error('Transfer error: ' . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Unable to complete transfer'
        ], 500);
    }
}

// Resolve transaction recipient by email or wallet address(confirming details)
public function resolve(Request $request)
{
    $request->validate([
        'recipient' => 'required|string'
    ]);

    $input = $request->recipient;

    // Case 1: Email
    if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
        $user = User::where('email', $input)->first();

        if (!$user || !$user->wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'type' => 'email',
            'name' => $user->name,
            'email' => $user->email,
            'wallet_address' => $user->wallet->address,
            'verified' => $user->hasVerifiedEmail()
        ]);
    }

    // Case 2: Wallet address
    $wallet = Wallet::where('address', $input)->first();

    if (!$wallet) {
        return response()->json([
            'status' => 'error',
            'message' => 'Wallet not found'
        ], 404);
    }

    return response()->json([
        'status' => 'success',
        'type' => 'wallet',
        'name' => $wallet->user->name ?? 'Unknown',
        'wallet_address' => $wallet->address,
        'verified' => $wallet->user?->hasVerifiedEmail() ?? false
    ]);
}



    /**
     * Get transaction history
     */
    /*
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['wallet_id', 'wallet_address', 'type', 'status', 'start_date', 'end_date', 'has_recipient']);
            $perPage = $request->get('per_page', 20);

            $result = $this->transactionService->getTransactions($filters, $perPage);

            return response()->json([
                'status' => 'success',
                'data' => $result['data'],
                'meta' => $result['meta'],
                'filters' => $result['filters']
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    } */
}