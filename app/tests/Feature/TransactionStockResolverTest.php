<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use App\Services\StockValidationService;
use App\Support\StockValidationResult;
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
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user);

        $this->postJson('/api/cash/deposit', [
            'amount' => 100000,
            'reason' => 'test seed',
        ])->assertCreated();

        $symbol = 'N'.strtoupper(Str::random(4));

        $this->mock(StockValidationService::class, function ($mock) {
            $mock->shouldReceive('validateAndPersist')
                ->andReturnUsing(function (string $inputSymbol, ?string $exchange, ?string $name) {
                    $stock = Stock::query()->create([
                        'symbol' => $inputSymbol,
                        'exchange' => $exchange ?? 'NSE',
                        'name' => $name ?? $inputSymbol,
                        'is_active' => true,
                        'is_benchmark' => false,
                    ]);

                    return StockValidationResult::valid($stock, 'test');
                });
        });

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
            'profile_id' => $profile->id,
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
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'REUSE',
            'exchange' => 'NSE',
            'name' => 'Reuse Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $this->postJson('/api/cash/deposit', [
            'amount' => 100000,
            'reason' => 'test seed',
        ])->assertCreated();

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
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
        ]);
    }
}
