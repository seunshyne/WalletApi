<?php

namespace App\Services;

use App\Models\User;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'reference' => $data['reference'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'sender_transaction' => $data['sender_transaction'] ?? null,
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
            'reference' => null,
            'idempotency_key' => null,
            'sender_transaction' => null,
            'recipient_transaction' => null,
            'wallet_balance' => null,
            'recipient_wallet_balance' => null,
            'code' => $code,
        ];
    }

    /**
     * Process a wallet transaction safely and idempotently.
     *
     * @param array{
     *   wallet_id: int,
     *   type: 'credit'|'debit',
     *   amount: float,
     *   reference: string,
     *   idempotency_key: string
     * } $data
     *
     * @return array{
     *   status: string,
     *   transaction: Transaction,
     *   wallet_balance: float|null
     * }
     *
     * @throws \Exception
     */
    public function process(array $data): array
    {
        // 1️⃣ Idempotency check
        $existing = Transaction::where('idempotency_key', '=', $data['idempotency_key'], 'and')->first();

        if ($existing) {
            return [
                'status' => 'duplicate',
                'transaction' => $existing,
                'wallet_balance' => $existing->wallet->balance ?? null,
            ];
        }

        // 2️⃣ Lock wallet row to avoid race conditions
        $wallet = Wallet::where('id', $data['wallet_id'])->lockForUpdate()->firstOrFail();

        // 3️⃣ Overdraft protection
        if ($data['type'] === 'debit' && $wallet->balance < $data['amount']) {
            throw new Exception('Insufficient balance', 422);
        }

        // 4️⃣ Atomic transaction
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
                'description'=> $transaction->description,
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

    // Resolve recipient wallet by email or wallet address(sending by either wallet address or email)
    private function resolveRecipientWallet(string $recipient): Wallet
    {

        // Sending via email
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', '=', $recipient, 'and')->first();

            if (!$user || !$user->wallet) {
                throw new Exception('Recipient user not found', 404);
            }

            if (! $user->hasVerifiedEmail()) {
                throw new Exception('Recipient email not verified', 403);
            }
            return Wallet::where('id', $user->wallet->id)->lockForUpdate()->first();
        }

        // sending by wallet address
        $wallet = Wallet::where('address', $recipient)->lockForUpdate()->first();

        if (!$wallet) {
            throw new Exception('Recipient wallet not found', 404);
        }
        return $wallet;
    }



    /**
     * Transfer between wallets using addresses
     */
    public function transfer(array $data): array
    {
        $idempotencyKey = $data['client_idempotency_key'] ?? $data['idempotency_key'] ?? null;
        $reference = null;

        if (!is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return $this->errorResponse('client_idempotency_key is required', 422);
        }

        try {
            $existingSenderTransaction = Transaction::where('idempotency_key', '=', $idempotencyKey, 'and')
                ->where('type', 'debit')
                ->first();
            $existingRecipientTransaction = Transaction::where('idempotency_key', '=', $idempotencyKey, 'and')
                ->where('type', 'credit')
                ->first();

            if ($existingSenderTransaction || $existingRecipientTransaction) {
                $existingReference = $existingSenderTransaction->reference ?? $existingRecipientTransaction->reference;
                $senderWalletBalance = $existingSenderTransaction?->wallet?->balance;
                $recipientWalletBalance = $existingRecipientTransaction?->wallet?->balance;

                return $this->successResponse('Transfer already processed', [
                    'reference' => $existingReference,
                    'idempotency_key' => $idempotencyKey,
                    'sender_transaction' => $existingSenderTransaction,
                    'recipient_transaction' => $existingRecipientTransaction,
                    'wallet_balance' => $senderWalletBalance,
                    'recipient_wallet_balance' => $recipientWalletBalance,
                ]);
            }

            $user = Auth::user();
            $senderWalletId = $user?->wallet?->id;
            if (!$senderWalletId) {
                return $this->errorResponse('Sender wallet not found', 404);
            }

            DB::beginTransaction();

            $senderWallet = Wallet::where('id', $senderWalletId)->lockForUpdate()->first();
            if (!$senderWallet) {
                DB::rollBack();
                return $this->errorResponse('Sender wallet not found', 404);
            }

            $recipientWallet = $this->resolveRecipientWallet($data['recipient']);
            if (!$recipientWallet) {
                DB::rollBack();
                return $this->errorResponse('Recipient wallet not found', 404);
            }

            if ($senderWallet->id === $recipientWallet->id) {
                DB::rollBack();
                return $this->errorResponse('You cannot transfer to your own wallet', 400);
            }

            $amount = (string)($data['amount']);
            if ($senderWallet->balance < $amount) {
                DB::rollBack();
                return $this->errorResponse('Insufficient balance', 400);
            }

            // Generate keys safely
            $reference = 'TRX-' . Str::uuid();

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
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'status' => 'successful',

                'metadata' => [
                    'recipient_wallet_id' => $recipientWallet->id,
                    'recipient_wallet_address' => $recipientWallet->address,
                ]
            ]);

            // Credit recipient
            $recipientWallet->balance += (string)$amount;
            $recipientWallet->save();

            $recipientTransaction = Transaction::create([
                'wallet_id' => $recipientWallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'sender_address' => $senderWallet->address,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'description' => $data['description'] ?? "Received from {$senderWallet->address}",
                'status' => 'successful',

                'metadata' => [
                    'sender_wallet_id' => $senderWallet->id,
                    'sender_wallet_address' => $senderWallet->address,
                ],
            ]);

            DB::commit();

            return $this->successResponse('Transfer successful', [
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'sender_transaction' => $senderTransaction,
                'recipient_transaction' => $recipientTransaction,
                'wallet_balance' => $senderWallet->balance,
                'recipient_wallet_balance' => $recipientWallet->balance,
            ]);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $code = (int) $e->getCode();
            $statusCode = ($code >= 400 && $code < 500) ? $code : 500;
            $user = Auth::user();
            $logPayload = [
                'recipient' => $data['recipient'] ?? null,
                'amount' => $data['amount'] ?? null,
                'has_description' => array_key_exists('description', $data),
                'client_idempotency_key' => isset($data['client_idempotency_key']) ? Str::limit((string) $data['client_idempotency_key'], 20, '...') : null,
                'idempotency_key' => isset($data['idempotency_key']) ? Str::limit((string) $data['idempotency_key'], 20, '...') : null,
            ];

            Log::error('Transfer failed', [
                'message' => $e->getMessage(),
                'code' => $code,
                'trace' => $e->getTraceAsString(),
                'user_id' => $user?->id,
                'request' => $logPayload,
                'reference' => $reference ?? null,
                'idempotency_key' => $idempotencyKey ?? null,
            ]);

            return $this->errorResponse(
                $statusCode === 500 ? 'Unable to complete transfer' : $e->getMessage(),
                $statusCode
            );
        }
    }
}
