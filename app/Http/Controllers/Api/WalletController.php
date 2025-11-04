<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use App\Models\Wallet;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function credit(Request $request, $walletId)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $wallet = Wallet::findOrFail($walletId);

        $result = $this->walletService->credit($wallet, $data['amount']);

        return response()->json([
            'message' => 'Wallet credited successfully',
            'transaction' => $result['transaction'],
            'balance' => $result['balance'],
        ]);
    }

    public function debit(Request $request, $walletId)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $wallet = Wallet::findOrFail($walletId);

        try {
            $result = $this->walletService->debit($wallet, $data['amount']);

            return response()->json([
                'message' => 'Wallet debited successfully',
                'transaction' => $result['transaction'],
                'balance' => $result['balance'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function transactions($walletId)
    {
        $wallet = Wallet::findOrFail($walletId);

        $transactions = $this->walletService->transactions($wallet);

        return response()->json($transactions);
    }
}
