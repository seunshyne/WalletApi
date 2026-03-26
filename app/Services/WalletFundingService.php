<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WalletFunding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletFundingService
{
    public function completePaystackFunding(WalletFunding $walletFunding, array $paymentData, string $reference, string $payloadType): WalletFunding
    {
        return DB::transaction(function () use ($walletFunding, $paymentData, $reference, $payloadType) {
            $lockedFunding = WalletFunding::whereKey($walletFunding->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFunding->isCompleted()) {
                return $lockedFunding;
            }

            $wallet = $lockedFunding->wallet()
                ->lockForUpdate()
                ->firstOrFail();

            $amount = $this->extractAmountFromPaystackPayload($paymentData, $lockedFunding);
            $wallet->increment('balance', $amount);

            $lockedFunding->update([
                'status' => WalletFunding::STATUS_COMPLETED,
                'paid_at' => now(),
                'metadata' => array_merge($lockedFunding->metadata ?? [], [
                    $payloadType => $paymentData,
                ]),
            ]);

            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'reference' => 'FUND-' . Str::uuid(),
                'idempotency_key' => 'paystack_funding_' . $reference,
                'description' => 'Wallet funding via Paystack',
                'status' => WalletFunding::STATUS_COMPLETED,
                'metadata' => [
                    'funding_id' => $lockedFunding->id,
                    'provider' => WalletFunding::PROVIDER_PAYSTACK,
                    'provider_reference' => $reference,
                ],
            ]);

            return $lockedFunding->fresh();
        });
    }

    public function updatePaystackFundingStatus(
        WalletFunding $walletFunding,
        string $status,
        array $paymentData = [],
        ?string $payloadType = null
    ): WalletFunding {
        $metadata = $walletFunding->metadata ?? [];

        if ($payloadType) {
            $metadata[$payloadType] = $paymentData;
        }

        $walletFunding->update([
            'status' => $status,
            'metadata' => $metadata,
        ]);

        return $walletFunding->fresh();
    }

    private function extractAmountFromPaystackPayload(array $paymentData, WalletFunding $walletFunding): float
    {
        if (isset($paymentData['amount']) && is_numeric($paymentData['amount'])) {
            return (float) $paymentData['amount'] / 100;
        }

        return (float) $walletFunding->amount;
    }
}
