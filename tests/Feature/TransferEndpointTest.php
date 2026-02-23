<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithWallet(float $balance = 0): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'address' => 'WAL' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'balance' => $balance,
            'currency' => 'NGN',
        ]);

        return [$user, $wallet];
    }

    public function test_successful_transfer(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(1000);
        [, $recipientWallet] = $this->createUserWithWallet(250);

        Sanctum::actingAs($sender);

        $payload = [
            'recipient' => $recipientWallet->address,
            'amount' => 150,
            'description' => 'Rent support',
            'client_idempotency_key' => (string) Str::uuid(),
        ];

        $response = $this->postJson('/api/transactions/transfer', $payload);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Transfer successful')
            ->assertJsonPath('idempotency_key', $payload['client_idempotency_key'])
            ->assertJsonStructure([
                'status',
                'message',
                'reference',
                'idempotency_key',
                'sender_transaction',
                'recipient_transaction',
                'wallet_balance',
                'recipient_wallet_balance',
            ]);

        $senderWallet->refresh();
        $recipientWallet->refresh();

        $this->assertEquals('850.00', $senderWallet->balance);
        $this->assertEquals('400.00', $recipientWallet->balance);
    }

    public function test_duplicate_idempotency_returns_existing_result_without_double_debit(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(1000);
        [, $recipientWallet] = $this->createUserWithWallet(200);

        Sanctum::actingAs($sender);

        $payload = [
            'recipient' => $recipientWallet->address,
            'amount' => 100,
            'description' => 'Duplicate safety',
            'client_idempotency_key' => (string) Str::uuid(),
        ];

        $firstResponse = $this->postJson('/api/transactions/transfer', $payload);
        $secondResponse = $this->postJson('/api/transactions/transfer', $payload);

        $firstResponse->assertOk()->assertJsonPath('status', 'success');
        $secondResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Transfer already processed')
            ->assertJsonPath('idempotency_key', $payload['client_idempotency_key']);

        $senderWallet->refresh();
        $this->assertEquals('900.00', $senderWallet->balance);

        $this->assertCount(
            2,
            Transaction::where('idempotency_key', $payload['client_idempotency_key'])->get()
        );
    }

    public function test_missing_client_idempotency_key_returns_422(): void
    {
        [$sender] = $this->createUserWithWallet(1000);
        [, $recipientWallet] = $this->createUserWithWallet(200);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/transfer', [
            'recipient' => $recipientWallet->address,
            'amount' => 100,
            'description' => 'No key',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonValidationErrors(['client_idempotency_key']);
    }

    public function test_insufficient_balance_returns_400(): void
    {
        [$sender] = $this->createUserWithWallet(50);
        [, $recipientWallet] = $this->createUserWithWallet(500);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/transfer', [
            'recipient' => $recipientWallet->address,
            'amount' => 150,
            'description' => 'Too much',
            'client_idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Insufficient balance');
    }

    public function test_recipient_not_found_returns_404(): void
    {
        [$sender] = $this->createUserWithWallet(500);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/transfer', [
            'recipient' => 'WAL9999999',
            'amount' => 100,
            'description' => 'Unknown recipient',
            'client_idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Recipient wallet not found');
    }

    public function test_self_transfer_is_blocked_with_400(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(500);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/transfer', [
            'recipient' => $senderWallet->address,
            'amount' => 100,
            'description' => 'Self transfer',
            'client_idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'You cannot transfer to your own wallet');
    }
}
