<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Exception;

class WalletService
{
    /** Standard success response */
    protected function successResponse(string $message, $data = [], int $code = 200): array
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'code' => $code,
        ];
    }

    public function createForUser(User $user) {
                if ($user->wallet) {
                return;
            }
            Wallet::firstOrCreate([
                'user_id' => $user->id],
                [
                'address' => $this->generateAddress(),
                'balance' => 0.00,
                'currency' => 'NGN',
            ]);
    }

    public function generateAddress(): string
    {
        return 'WAL' . str_pad(random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    /** Standard error response */
    protected function errorResponse(string $message, int $code = 400): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
    }

    // public function debitWallet($walletId, $amount){
    //     Wallet::where('id', $walletId)
    //     ->update([
    //         'balance' => DB::raw('balance - ' . $amount)
    //     ]);
    // } 

    // public function creditWallet($address, $amount){
    //     Wallet::where('address', $address)
    //     ->update([
    //         'balance' => DB::raw('balance + ' . $amount)
    //     ]);
    // } 

    public function sendMoney(array $data)
{
    // find sender
    $sender = Wallet::where('id', $data['wallet_id'])->first();
    if (!$sender) {
        return [
            'status' => 'error',
            'error' => 'Login to account'
        ];
    }

    // find receiver
    $receiver = Wallet::where('address', $data['recipient_address'])->first();
    if (!$receiver) {
        return ['status' => 'error', 
        'message' => 'Enter correct account number'
    ];

    }

    // balance check
    if ($sender->balance < $data['amount']) {
        return [
            'status' =>'error',
            'message' => 'Insufficient balance'];
    }

    try {
        // atomic transaction
        DB::transaction(function () use ($sender, $receiver, $data) {

            // debit sender
            $this->debit($sender, $data['amount'], $data['idempotency_key'], 'Transfer sent');

            // credit receiver
            $this->credit($receiver, $data['amount'], $data['idempotency_key'].'_rcv', 'Transfer received');

        });

        return ['status' => 'success', 
        'message' => 'Successful Transaction'];

    } catch (Exception $e) {
        Log::error('Send money failed', ['error' => $e->getMessage()]);
        return ['status' => 'error',
                'error' => 'Failed transaction'];
    }
}



    /**
     * Credit wallet safely using idempotency + locking
     */
    public function credit(Wallet $wallet, float $amount, ?string $idempotencyKey = null, ?string $description = null): array
{
    $this->validateAmount($amount);

    $idempotencyKey = $idempotencyKey ?: $this->generateIdempotencyKey();

    if ($existing = $this->checkDuplicateTransaction($idempotencyKey)) {
        return $this->duplicateResponse($existing, $wallet);
    }

    return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $description) {

        $lockedWallet = Wallet::where('id', $wallet->id)
            ->lockForUpdate()
            ->firstOrFail();

        $previous = $lockedWallet->balance;
        $lockedWallet->balance = bcadd($previous, $amount, 2);
        $lockedWallet->save();

        $transaction = $this->createTransaction(
            $lockedWallet,
            'credit',
            $amount,
            $previous,
            $idempotencyKey,
            $description
        );

        Cache::put($this->getCacheKey($idempotencyKey), $transaction->id, 86400);

        return $this->successResponse($transaction, $lockedWallet);
    });
}
    /**
     * Debit wallet safely using locking + idempotency
     */
    public function debit(Wallet $wallet, float $amount, ?string $idempotencyKey = null, ?string $description = null): array
    {
        $this->validateAmount($amount);

        $idempotencyKey = $idempotencyKey ?: $this->generateIdempotencyKey();

        if ($existing = $this->checkDuplicateTransaction($idempotencyKey)) {
            return $this->duplicateResponse($existing, $wallet);
        }

        try {
            return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $description) {
                $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                if ($lockedWallet->balance < $amount) {
                    throw new Exception('Insufficient balance');
                }

                $previous = $lockedWallet->balance;
                $lockedWallet->balance = bcsub($lockedWallet->balance, $amount, 2);
                $lockedWallet->save();

                $transaction = $this->createTransaction(
                    $lockedWallet,
                    'debit',
                    $amount,
                    $previous,
                    $idempotencyKey,
                    $description
                );

                Cache::put($this->getCacheKey($idempotencyKey), $transaction->id, 86400);

                return $this->successResponse($transaction, $lockedWallet);
            });
        } catch (Exception $e) {
            Log::error('Wallet debit failed', [
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            throw new Exception('Failed to debit wallet.');
        }
    }

    /**
     * Transfer between two wallets in atomic and race-safe manner
     */
/* @param string $fromWalletAddress Sender's wallet address
 * @param string $toWalletAddress Recipient's wallet address
 * @param float $amount Amount to transfer
 * @param string|null $idempotencyKey Unique key to prevent duplicate transactions
 * @param string|null $description Optional description
 * @return array
 * @throws Exception
 */
public function transferByAddress(string $fromWalletAddress, string $toWalletAddress, float $amount, ?string $idempotencyKey = null, ?string $description = null): array
{
    $this->validateAmount($amount);

    // Find sender wallet by address
    $fromWallet = Wallet::where('address', $fromWalletAddress)->first();
    if (!$fromWallet) {
        throw new Exception('Sender wallet not found');
    }

    // Find recipient wallet by address
    $toWallet = Wallet::where('address', $toWalletAddress)->first();
    if (!$toWallet) {
        throw new Exception('Recipient wallet not found');
    }

    // Prevent self-transfer
    if ($fromWallet->id === $toWallet->id) {
        throw new Exception('Cannot transfer to the same wallet');
    }

    $idempotencyKey = $idempotencyKey ?: $this->generateIdempotencyKey();

    // Check for duplicate transaction
    if ($existing = $this->checkDuplicateTransaction($idempotencyKey)) {
        return [
            'status' => 'duplicate',
            'transaction' => $existing,
            'message' => 'Duplicate transfer request'
        ];
    }

    try {
        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $idempotencyKey, $description) {
            
            // Lock both wallets in consistent order to prevent deadlocks
            $walletIds = [$fromWallet->id, $toWallet->id];
            sort($walletIds);

            $locked = Wallet::whereIn('id', $walletIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFrom = $locked[$fromWallet->id];
            $lockedTo = $locked[$toWallet->id];

            // Validate sufficient balance
            if ($lockedFrom->balance < $amount) {
                throw new Exception('Insufficient balance');
            }

            // Store previous balances
            $prevFrom = $lockedFrom->balance;
            $prevTo = $lockedTo->balance;

            // Update balances
            $lockedFrom->balance = bcsub($lockedFrom->balance, $amount, 2);
            $lockedTo->balance = bcadd($lockedTo->balance, $amount, 2);

            $lockedFrom->save();
            $lockedTo->save();

            // Generate shared reference for linking transactions
            $reference = $this->generateReference();

            // Create debit transaction for sender
            $debit = $this->createTransaction(
                $lockedFrom,
                'debit',
                $amount,
                $prevFrom,
                $idempotencyKey,
                $description ?: 'Transfer to ' . $lockedTo->address,
                $reference
            );

            // Create credit transaction for recipient
            $credit = $this->createTransaction(
                $lockedTo,
                'credit',
                $amount,
                $prevTo,
                $idempotencyKey . '-credit',
                $description ?: 'Received from ' . $lockedFrom->address,
                $reference
            );

            // Cache idempotency key
            Cache::put($this->getCacheKey($idempotencyKey), $debit->id, 86400);

            return [
                'status' => 'success',
                'debit' => $debit,
                'credit' => $credit,
                'sender_balance' => $lockedFrom->balance,
                'recipient_balance' => $lockedTo->balance,
                'message' => 'Transfer completed successfully'
            ];
        });
    } catch (Exception $e) {
        Log::error('Wallet transfer failed', [
            'from_address' => $fromWalletAddress,
            'to_address' => $toWalletAddress,
            'amount' => $amount,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new Exception('Transfer failed: ' . $e->getMessage());
    }
}

/**
 * Original transfer method using Wallet models (keep for backward compatibility)
 */
public function transfer(Wallet $fromWallet, Wallet $toWallet, float $amount, ?string $idempotencyKey = null, ?string $description = null): array
{
    return $this->transferByAddress(
        $fromWallet->address,
        $toWallet->address,
        $amount,
        $idempotencyKey,
        $description
    );
}
    /** Utility helpers */

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new Exception('Amount must be greater than zero');
        }
        if ($amount > 1000000000) {
            throw new Exception('Amount exceeds allowed limit');
        }
    }

    private function generateIdempotencyKey(): string
    {
        return 'IDM-' . Str::uuid();
    }

    private function generateReference(): string
    {
        return 'TXN-' . Str::upper(Str::random(12));
    }

    private function createTransaction(Wallet $wallet, string $type, float $amount, $previous, string $idempotencyKey, ?string $description = null, ?string $reference = null): Transaction
    {
        $req = request();

        return Transaction::create([
            'wallet_id'||'wallet_address' => $wallet->id || $wallet->address,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $wallet->balance,
            'previous_balance' => $previous,
            'reference' => $reference ?: $this->generateReference(),
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
            'status' => 'completed',
            'metadata' => [
                'ip' => $req ? $req->ip() : null,
                'user_agent' => $req ? $req->userAgent() : null,
            ],
        ]);
    }

    private function checkDuplicateTransaction(string $key): ?Transaction
    {
        if ($id = Cache::get($this->getCacheKey($key))) {
            return Transaction::find($id);
        }
        return Transaction::where('idempotency_key', $key)->first();
    }

    private function getCacheKey(string $key): string
    {
        return 'idempotency:' . md5($key);
    }

    private function duplicateResponse(Transaction $transaction, Wallet $wallet): array
    {
        return [
            'status' => 'duplicate',
            'transaction' => $transaction,
            'balance' => $wallet->fresh()->balance,
            'message' => 'Duplicate transaction request'
        ];
    }

    /**
     * Fetch transactions with pagination and optional filters
     */
    public function getTransactions(Wallet $wallet, array $filters = [], int $perPage = 20)
    {
        $query = Transaction::where('wallet_id', $wallet->id);

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (!empty($filters['reference'])) {
            $query->where('reference', 'like', '%' . $filters['reference'] . '%');
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'transactions' => $transactions,
            'wallet' => [
                'id' => $wallet->id,
                'address' => $wallet->address,
                'balance' => $wallet->fresh()->balance,
                'currency' => $wallet->currency ?? null,
            ]
        ];
    }

    /**
     * Get current wallet balance
     */
    public function getBalance(Wallet $wallet): float
    {
        return (float) $wallet->fresh()->balance;
    }
}
