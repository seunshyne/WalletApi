<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'type' => $this->faker->randomElement(['credit', 'debit']),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'reference' => strtoupper(Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
