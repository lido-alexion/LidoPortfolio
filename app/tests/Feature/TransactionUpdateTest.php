<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_their_transaction(): void
    {
        $user = User::query()->create([
            'name' => 'Update User',
            'email' => 'update-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'UPD1',
            'exchange' => 'NSE',
            'name' => 'Update Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $transaction = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        $this->actingAs($user);

        $response = $this->putJson("/api/transactions/{$transaction->id}", [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('portfolio_transactions', [
            'id' => $transaction->id,
            'price' => 110,
        ]);
    }

    public function test_owner_can_delete_their_transaction(): void
    {
        $user = User::query()->create([
            'name' => 'Delete User',
            'email' => 'delete-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'DEL1',
            'exchange' => 'NSE',
            'name' => 'Delete Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $transaction = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 75,
            'fees' => 0,
            'transaction_date' => '2026-03-01',
        ]);

        $this->actingAs($user);

        $response = $this->deleteJson("/api/transactions/{$transaction->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('portfolio_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_cannot_delete_buy_when_orphan_sells_would_remain(): void
    {
        $user = User::query()->create([
            'name' => 'Block Delete',
            'email' => 'block-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'BLK1',
            'exchange' => 'NSE',
            'name' => 'Block Delete Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $buy = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 4,
            'price' => 120,
            'fees' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        $this->actingAs($user);

        $response = $this->deleteJson("/api/transactions/{$buy->id}");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['transaction']);
        $this->assertDatabaseHas('portfolio_transactions', ['id' => $buy->id]);
    }

    public function test_cannot_delete_another_users_transaction(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $ownerProfile = $this->defaultPortfolioFor($owner);
        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($other);

        $stock = Stock::query()->create([
            'symbol' => 'DEL2',
            'exchange' => 'NSE',
            'name' => 'Delete Test 2',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $transaction = Transaction::query()->create([
            'profile_id' => $ownerProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2026-03-02',
        ]);

        $this->actingAs($other);

        $this->deleteJson("/api/transactions/{$transaction->id}")->assertNotFound();
        $this->assertDatabaseHas('portfolio_transactions', ['id' => $transaction->id]);
    }
}


