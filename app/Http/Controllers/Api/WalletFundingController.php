<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletFunding;
use Illuminate\Http\Request;
use App\Services\PaystackService;
use App\Services\WalletFundingService;
use Illuminate\Support\Facades\Auth;

class WalletFundingController extends Controller
{
    public function __construct(
        protected PaystackService $paystack,
        protected WalletFundingService $walletFundingService
    )
    {
    }

    // Step 1: Initialize payment
    public function initiate(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $user = Auth::user();

        $data = [
            'email'        => $user->email,
            'amount'       => $request->amount,
            'user_id'      => $user->id,
            'callback_url' => config('app.frontend_url') . '/wallet/verify',
        ];

        $response = $this->paystack->initializePayment($data);

        if ($response['status']) {
            WalletFunding::updateOrCreate(
                [
                    'provider' => WalletFunding::PROVIDER_PAYSTACK,
                    'provider_reference' => $response['data']['reference'],
                ],
                [
                    'user_id' => $user->id,
                    'wallet_id' => $user->wallet->id,
                    'amount' => $request->amount,
                    'status' => WalletFunding::STATUS_PENDING,
                    'metadata' => [
                        'authorization_url' => $response['data']['authorization_url'] ?? null,
                        'access_code' => $response['data']['access_code'] ?? null,
                    ],
                ]
            );

            return response()->json([
                'status'       => true,
                'message'      => 'Payment initialized',
                'payment_url'  => $response['data']['authorization_url'],
                'reference'    => $response['data']['reference'],
            ]);
        }

        return response()->json([
            'status'  => false,
            'message' => 'Could not initialize payment',
        ], 500);
    }

    // Step 2: Verify payment and credit wallet
    public function verify(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $user = Auth::user();
        $walletFunding = WalletFunding::where('provider', WalletFunding::PROVIDER_PAYSTACK)
            ->where('provider_reference', $request->reference)
            ->where('user_id', $user->id)
            ->first();

        if (!$walletFunding) {
            return response()->json([
                'status' => false,
                'message' => 'Funding record not found',
            ], 404);
        }

        $response = $this->paystack->verifyPayment($request->reference);

        if ($response['status'] && $response['data']['status'] === 'success') {
            if ($walletFunding->isCompleted()) {
                return response()->json([
                    'status' => true,
                    'message' => 'Wallet already funded',
                    'amount' => $walletFunding->amount,
                    'balance' => $user->wallet->fresh()->balance,
                ]);
            }

            $completedFunding = $this->walletFundingService->completePaystackFunding(
                $walletFunding,
                $response['data'],
                $request->reference,
                'verification_response'
            );

            return response()->json([
                'status'  => true,
                'message' => 'Wallet funded successfully',
                'amount'  => $completedFunding->amount,
                'balance' => $user->wallet->fresh()->balance,
            ]);
        }

        if ($walletFunding->isPending()) {
            $this->walletFundingService->updatePaystackFundingStatus(
                $walletFunding,
                WalletFunding::STATUS_FAILED,
                $response['data'] ?? $response,
                'verification_response'
            );
        }

        return response()->json([
            'status'  => false,
            'message' => 'Payment verification failed',
        ], 400);
    }
}
