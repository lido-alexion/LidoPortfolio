<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionStockResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_buy_transaction_creates_stock_from_symbol_without_prior_master_entry(): void
    {
        $user = User::query()->create([
            'name' => 'Tx User',
            'email' => 'tx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user);

        $symbol = 'N'.strtoupper(Str::random(4));

        \Illuminate\Support\Facades\Http::fake([
            'https://www.nseindia.com/api/quote-equity*' => \Illuminate\Support\Facades\Http::response([
                'info' => ['companyName' => 'New Via Transaction', 'symbol' => $symbol],
            ], 200),
        ]);

        $response = $this->postJson('/api/transactions', [
            'symbol' => $symbol,
            'name' => 'New Via Transaction',
            'exchange' => 'NSE',
            'type' => 'buy',
            'quantity' => 2,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('portfolio_stocks', [
            'symbol' => $symbol,
            'name' => 'New Via Transaction',
        ]);
        $this->assertDatabaseHas('portfolio_transactions', [
            'user_id' => $user->id,
            'type' => 'buy',
        ]);
    }

    public function test_buy_transaction_reuses_existing_stock_by_symbol(): void
    {
        $user = User::query()->create([
            'name' => 'Tx User 2',
            'email' => 'tx2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'REUSE',
            'exchange' => 'NSE',
            'name' => 'Reuse Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/transactions', [
            'symbol' => 'REUSE',
            'type' => 'buy',
            'quantity' => 1,
            'price' => 10,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(1, Stock::query()->where('symbol', 'REUSE')->count());
        $this->assertDatabaseHas('portfolio_transactions', [
            'user_id' => $user->id,
            'stock_id' => $stock->id,
        ]);
    }
}
