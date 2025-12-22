<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Exception;

class TransactionService
{
    protected function successResponse(string $message, $data = [], int $code = 200): array
    {
        return [
            'status' => 'success',
            'message' => $message,
            'transaction' => $data['transaction'] ?? null,
            'recipient_transaction' => $data['recipient_transaction'] ?? null,
            'wallet_balance' => $data['wallet_balance'] ?? null,
            'recipient_wallet_balance' => $data['recipient_wallet_balance'] ?? null,
            'code' => $code,
        ];
    }

    protected function errorResponse(string $message, int $code = 400): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
    }


    public function process(array $data)
    {

        // Auto-set type to 'debit' if recipient_wallet_address is provided
        if (!empty($data['recipient_address']) && empty($data['type'])) {
            $data['type'] = 'debit';
        }

        $this->validateTransactionData($data);

        if ($existing = $this->checkDuplicate($data['idempotency_key'])) {
            return $this->successResponse('Duplicate transaction', [
                'transaction' => $existing,
                'wallet_balance' => $existing->wallet->balance,
            ]);
        }

        try {
            $result = DB::transaction(function () use ($data) {

                // If this is a transfer to another wallet address, process both wallets
                if (!empty($data['recipient_address'])) {
                    return $this->processTransfer($data);
                }

                // Otherwise process single wallet transaction
                return $this->processSingleWallet($data);
            });

            return $this->successResponse('Transaction processed successfully', $result);
        } catch (Exception $e) {

            Log::error('Transaction failed', [
                'wallet_id' => $data['wallet_id'] ?? null,
                'recipient_wallet_address' => $data['recipient_wallet_address'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Transaction failed: ' . $e->getMessage(), 500);
        }
    }
    private function processTransfer(array $data): array
    {
        // Lock wallets in consistent order to prevent deadlocks
        $senderWallet = Wallet::where('id', $data['wallet_id'])->first();

        if (!$senderWallet) {
            throw new Exception('Sender wallet not found');
        }

        $recipientWallet = Wallet::where('address', $data['recipient_address'])->first();

        if (!$recipientWallet) {
            throw new Exception('Recipient wallet address not found: ' . $data['recipient_address']);
        }

        // Validate wallets are different
        if ($senderWallet->id === $recipientWallet->id) {
            throw new Exception('Cannot transfer to the same wallet');
        }

        // Lock both wallets in consistent order (by ID) to prevent deadlocks
        $walletIds = [$senderWallet->id, $recipientWallet->id];
        sort($walletIds);

        $lockedWallets = Wallet::whereIn('id', $walletIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lockedSender = $lockedWallets[$senderWallet->id];
        $lockedRecipient = $lockedWallets[$recipientWallet->id];

        // Validate sender has sufficient balance
        if ($lockedSender->balance < $data['amount']) {
            throw new Exception('Insufficient balance');
        }

        // Generate a shared reference for both transactions
        $sharedReference = $data['reference'] ?? $this->generateReference();

        // Process sender debit
        $senderPrevious = $lockedSender->balance;
        $senderNew = $senderPrevious - $data['amount'];
        $lockedSender->balance = $senderNew;
        $lockedSender->save();

        $senderTransaction = Transaction::create([
            'wallet_id' => $lockedSender->id,
            'type' => 'debit',
            'amount' => $data['amount'],
            'balance_after' => $senderNew,
            'reference' => $sharedReference,
            'idempotency_key' => $data['idempotency_key'],
            'description' => $data['description'] ?? 'Transfer to wallet ' . $lockedRecipient->address,
            'status' => 'completed',
            'metadata' => [
                'previous_balance' => $senderPrevious,
                'recipient_wallet_id' => $lockedRecipient->id,
                'recipient_address' => $lockedRecipient->address,
                'transfer_type' => 'outgoing',
                'ip' => request()->ip(),
                'agent' => request()->userAgent(),
                'processed_at' => now()->toISOString(),
            ],
        ]);

        // Process recipient credit
        $recipientPrevious = $lockedRecipient->balance;
        $recipientNew = $recipientPrevious + $data['amount'];
        $lockedRecipient->balance = $recipientNew;
        $lockedRecipient->save();

        $recipientTransaction = Transaction::create([
            'wallet_id' => $lockedRecipient->id,
            'type' => 'credit',
            'amount' => $data['amount'],
            'balance_after' => $recipientNew,
            'reference' => $sharedReference, // Use same reference
            'idempotency_key' => $data['idempotency_key'] . '_recipient',
            'description' => $data['recipient_description'] ?? 'Received from wallet ' . $lockedSender->address,
            'status' => 'completed',
            'metadata' => [
                'previous_balance' => $recipientPrevious,
                'sender_wallet_id' => $lockedSender->id,
                'sender_wallet_address' => $lockedSender->address,
                'sender_transaction_id' => $senderTransaction->id,
                'transfer_type' => 'incoming',
                'processed_at' => now()->toISOString(),
            ],
        ]);

        // Update sender transaction with recipient transaction ID
        $senderTransaction->update([
            'metadata' => array_merge($senderTransaction->metadata ?? [], [
                'recipient_transaction_id' => $recipientTransaction->id
            ])
        ]);

        Cache::put(
            $this->idempotencyKey($data['idempotency_key']),
            $senderTransaction->id,
            86400
        );

        return [
            'transaction' => $senderTransaction,
            'recipient_transaction' => $recipientTransaction,
            'wallet_balance' => $senderNew,
            'recipient_wallet_balance' => $recipientNew,
        ];
    }

    private function processSingleWallet(array $data): array
    {
        // Get wallet by ID for single transactions
        $wallet = Wallet::where('id', $data['wallet_id'])
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception('Wallet not found');
        }

        $this->validateTransaction($wallet, $data);

        $previous = $wallet->balance;
        $new = $data['type'] === 'credit'
            ? $previous + $data['amount']
            : $previous - $data['amount'];

        $wallet->balance = $new;
        $wallet->save();

        $transaction = Transaction::create([
            'wallet_id' => $wallet->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'balance_after' => $new,
            'reference' => $data['reference'] ?? $this->generateReference(),
            'idempotency_key' => $data['idempotency_key'],
            'description' => $data['description'] ?? null,
            'status' => 'completed',
            'metadata' => [
                'previous_balance' => $previous,
                'ip' => request()->ip(),
                'agent' => request()->userAgent(),
                'processed_at' => now()->toISOString(),
            ],
        ]);

        Cache::put(
            $this->idempotencyKey($data['idempotency_key']),
            $transaction->id,
            86400
        );

        return [
            'transaction' => $transaction,
            'wallet_balance' => $new,
        ];
    }

    private function validateTransactionData(array $data): void
    {
        $required = ['wallet_id', 'amount', 'idempotency_key'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        // If recipient_wallet_address is provided, it's a transfer (debit)
        if (!empty($data['recipient_address'])) {
            $data['type'] = 'debit'; // Force type to debit for transfers
        } else {
            // For single wallet transactions, type is required
            if (empty($data['type'])) {
                throw new Exception("Missing required field: type");
            }

            if (!in_array($data['type'], ['credit', 'debit'])) {
                throw new Exception('Invalid transaction type');
            }
        }

        if ($data['amount'] <= 0) {
            throw new Exception('Amount must be greater than zero');
        }
    }

    private function validateTransaction(Wallet $wallet, array $data): void
    {
        if ($data['type'] === 'debit' && $wallet->balance < $data['amount']) {
            throw new Exception('Insufficient balance');
        }
    }

    private function checkDuplicate(string $key): ?Transaction
    {
        if ($id = Cache::get($this->idempotencyKey($key))) {
            return Transaction::find($id);
        }

        return Transaction::where('idempotency_key', $key)->first();
    }


    public function getTransactions(array $filters = [], int $perPage = 20): array
    {
        $user = Auth::user();

        if (!$user || !$user->wallet) {
            return [
                'data' => [],
                'meta' => [],
                'message' => 'User wallet not found',
                'status' => 'error'
            ];
        }

        $walletId = $user->wallet->id;

        // Get transactions where user is either sender or recipient
        $query = Transaction::with(['wallet.user'])
            ->where('wallet_id', $walletId); // Only get transactions for this wallet

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Transform transactions to add helpful metadata
        $transactions->getCollection()->transform(function ($transaction) {
            $metadata = is_array($transaction->metadata) ? $transaction->metadata : json_decode($transaction->metadata, true) ?? [];

            $base = [
                'id'         => $transaction->id,
                'amount'     => $transaction->amount,
                'type'       => $transaction->type,
                'reference'  => $transaction->reference,
                'created_at' => $transaction->created_at->toDateTimeString(),
            ];


            //Money sent
            if ($transaction->type === 'debit') {
                // Money sent — get recipient info from metadata
                $wallet = isset($metadata['recipient_wallet_id'])
                    ? Wallet::with('user')->find($metadata['recipient_wallet_id'])
                    : null;

                $base['recipient'] = [
                    'name'    => $wallet->user->name ?? 'Unknown',
                    'address' => $wallet->address ?? $metadata['recipient_wallet_address'] ?? 'Unknown',
                ];
            }

            //Money received
            if ($transaction->type === 'credit') {
                // Money received — get sender info from metadata
                $wallet = isset($metadata['sender_wallet_id'])
                    ? Wallet::with('user')->find($metadata['sender_wallet_id'])
                    : null;

                $base['sender'] = [
                    'name'    => $wallet->user->name ?? 'Unknown',
                    'address' => $wallet->address ?? $metadata['sender_wallet_address'] ?? 'Unknown',
                ];
            }
            return $base;
        });

        return [
            'status' => 'success',
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ]
        ];
    }

    private function idempotencyKey(string $key): string
    {
        return 'txn:idempotency:' . md5($key);
    }

    private function generateReference(): string
    {
        return 'TXN' . strtoupper(Str::random(10)) . time();
    }

    private function generateRecipientReference(string $senderReference): string
    {
        return 'RCV' . substr($senderReference, 3);
    }

    /**
     * Get transaction with recipient information
     */
    public function getTransactionWithRecipient(string $transactionId): ?array
    {
        $transaction = Transaction::with(['wallet.user'])
            ->find($transactionId);

        if (!$transaction) {
            return null;
        }

        $result = ['transaction' => $transaction];

        // If this transaction has a recipient, load recipient info
        if (!empty($transaction->metadata['recipient_transaction_id'])) {
            $recipientTransaction = Transaction::with(['wallet.user'])
                ->find($transaction->metadata['recipient_transaction_id']);

            $result['recipient_transaction'] = $recipientTransaction;
        }

        // If this transaction was received from someone, load sender info
        if (!empty($transaction->metadata['sender_transaction_id'])) {
            $senderTransaction = Transaction::with(['wallet.user'])
                ->find($transaction->metadata['sender_transaction_id']);

            $result['sender_transaction'] = $senderTransaction;
        }

        return $result;
    }

    /**
     * Find wallet by address and process transaction
     */
    public function processByWalletAddress(array $data): array
    {
        // Find wallet by address instead of ID
        $wallet = Wallet::where('address', $data['wallet_address'])->first();

        if (!$wallet) {
            return $this->errorResponse('Wallet address not found', 404);
        }

        // Replace wallet_address with wallet_id for processing
        $data['wallet_id'] = $wallet->id;
        unset($data['wallet_address']);

        return $this->process($data);
    }

    /**
     * Transfer between wallets using addresses
     */
    public function transferByAddress(array $data): array
    {
        DB::beginTransaction();

        try {
            //check if tranfer with same client_idempotency_key already exist
            $existingTransaction = Transaction::where('idempotency_key', $data['client_idempotency_key'])->first();
            if ($existingTransaction) {
                return $this->successResponse('Transfer already processed', [
                    'reference' => $existingTransaction->reference,
                    'idempotency_key' => $existingTransaction->idempotency_key,
                    'sender_transaction' => $existingTransaction->type === 'debit' ? $existingTransaction : null,
                    'recipient_transaction' => $existingTransaction->type === 'credit' ? $existingTransaction : null,
                ]);
            }
            $senderWallet = Wallet::where('id', Auth::user()->wallet->id)->lockForUpdate()->first();
;
            if (!$senderWallet) {
                return $this->errorResponse('Sender wallet not found', 404);
            }

            $recipientWallet = Wallet::where('address', $data['recipient_address'])->lockForUpdate()->first();
            if (!$recipientWallet) {
                return $this->errorResponse('Recipient wallet not found', 404);
            }

            if ($senderWallet->id === $recipientWallet->id) {
                return $this->errorResponse('You cannot transfer to your own wallet', 400);
            }

            $amount = floatval($data['amount']);
            if ($senderWallet->balance < $amount) {
                return $this->errorResponse('Insufficient balance', 400);
            }

            // Generate keys safely
            $reference = 'TRX-' . Str::uuid()->toString();

            $description = $data['description'] ?? "Transfer to {$recipientWallet->address}";

            // Debit sender
            $senderWallet->balance -= $amount;
            $senderWallet->save();

            $senderTransaction = Transaction::create([
                'wallet_id' => $senderWallet->id,
                'type' => 'debit',
                'amount' => $amount,
                'recipient_address' => $recipientWallet->address,
                'reference' => $reference,
                'idempotency_key' => $data['client_idempotency_key'],
                'description' => $data['description'] ?? "Transfer to {$recipientWallet->address}",
                'status' => 'successful',

                'metadata' => [
                    'recipient_wallet_id' => $recipientWallet->id,
                    'recipient_wallet_address' => $recipientWallet->address,
                ]
            ]);

            // Credit recipient
            $recipientWallet->balance += $amount;
            $recipientWallet->save();

            $recipientTransaction = Transaction::create([
                'wallet_id' => $recipientWallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'sender_address' => $senderWallet->address,
                'reference' => $reference,
                'idempotency_key' => $data['client_idempotency_key'],
                'description' => "Received from {$senderWallet->address}",
                'status' => 'successful',

                'metadata' => [
                    'sender_wallet_id' => $senderWallet->id,
                    'sender_wallet_address' => $senderWallet->address,
                ],
            ]);

            DB::commit();

            return $this->successResponse('Transfer successful', [
                'reference' => $reference,
                'idempotency_key' => $data['client_idempotency_key'],
                'sender_transaction' => $senderTransaction,
                'recipient_transaction' => $recipientTransaction,
                'wallet_balance' => $senderWallet->balance,
                'recipient_wallet_balance' => $recipientWallet->balance,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Transfer failed: ' . $e->getMessage());
            return $this->errorResponse('Unable to complete transfer', 500);
        }
    }
}
