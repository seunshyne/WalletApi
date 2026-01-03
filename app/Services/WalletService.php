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


    
    

    
   
}
