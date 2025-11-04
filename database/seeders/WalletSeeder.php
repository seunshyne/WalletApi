<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Wallet;

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ First, create or fetch a test user
        $user = User::first() ?? User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // ✅ Then, create a wallet for the user
        Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'NGN',
                'balance' => 100000.00,
            ]
        );
    }
}
