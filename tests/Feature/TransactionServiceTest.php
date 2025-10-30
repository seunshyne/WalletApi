<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;

class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransactionService::class);
    }

    #[Test]
    public function it_creates_a_credit_and_updates_balance_correctly(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 1000,
            'currency' => 'NGN',
        ]);

        $transaction = $this->service->process([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 500,
            'reference' => 'REF123',
            'idempotency_key' => 'unique-key-1',
        ]);

        $wallet->refresh();

        $this->assertEquals(1500, $wallet->balance);
        $this->assertEquals('credit', $transaction['transaction']->type);
        $this->assertDatabaseHas('transactions', ['reference' => 'REF123']);
    }

    #[Test]
    public function it_debits_wallet_and_prevents_overdraft(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 1000,
            'currency' => 'NGN',
        ]);

        // ✅ Successful debit
        $transaction = $this->service->process([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 400,
            'reference' => 'REF-DEBIT-1',
            'idempotency_key' => 'unique-key-2',
        ]);

        $wallet->refresh();
        $this->assertEquals(600, $wallet->balance);
        $this->assertEquals('debit', $transaction['transaction']->type);

        // ❌ Prevent overdraft
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->service->process([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 2000, // too high
            'reference' => 'REF-DEBIT-FAIL',
            'idempotency_key' => 'unique-key-3',
        ]);
    }

    #[Test]
    public function it_prevents_duplicate_transactions_with_the_same_idempotency_key()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 1000,
            'currency' => 'NGN',
        ]);

        // First request
        $firstTransaction = $this->service->process([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 500,
            'reference' => 'REF-UNIQUE-1',
            'idempotency_key' => 'same-key-123',
        ]);

        // Second request with SAME idempotency key
        $secondTransaction = $this->service->process([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 500,
            'reference' => 'REF-UNIQUE-2', // different ref, but same key
            'idempotency_key' => 'same-key-123',
        ]);

        $wallet->refresh();

        // ✅ Wallet should only increase ONCE
        $this->assertEquals(1500, $wallet->balance);

        // ✅ The two responses should point to the SAME transaction record
        $this->assertEquals(
            $firstTransaction['transaction']->id,
            $secondTransaction['transaction']->id,
            'Duplicate request created a new transaction instead of returning the existing one.'
        );

        // ✅ Database should have only one transaction with this idempotency key
        $this->assertDatabaseCount('transactions', 1);
    }
}
