<?php

namespace Database\Factories;

use App\Models\User;

use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), // or remove if not required
            'balance' => 0,
            'currency' => 'NGN',
        ];
    }
}
