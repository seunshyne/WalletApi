<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionFilterTest extends TestCase
{
    use RefreshDatabase;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and authenticate them
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum'); // 👈 Important!

        // Create some test transactions
        Transaction::factory()->create([
            'type' => 'credit',
            'reference' => 'REF-ABC',
            'created_at' => '2025-10-05',
        ]);

        Transaction::factory()->create([
            'type' => 'debit',
            'reference' => 'REF-XYZ',
            'created_at' => '2025-10-20',
        ]);
    }

    /** @test */
    public function it_filters_transactions_by_type(): void
    {
        $response = $this->getJson('/api/transactions?type=credit');

        $response->assertStatus(200)
                 ->assertJsonFragment(['reference' => 'REF-ABC'])
                 ->assertJsonMissing(['reference' => 'REF-XYZ']);
    }

    /** @test */
    public function it_filters_transactions_by_query(): void
    {
        $response = $this->getJson('/api/transactions?q=XYZ');

        $response->assertStatus(200)
                 ->assertJsonFragment(['reference' => 'REF-XYZ'])
                 ->assertJsonMissing(['reference' => 'REF-ABC']);
    }

    /** @test */
    public function it_filters_transactions_by_date_range(): void
    {
        $response = $this->getJson('/api/transactions?from=2025-10-01&to=2025-10-15');

        $response->assertStatus(200)
                 ->assertJsonFragment(['reference' => 'REF-ABC'])
                 ->assertJsonMissing(['reference' => 'REF-XYZ']);
    }
}
