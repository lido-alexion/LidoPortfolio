<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionIndexScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_scope_returns_only_transactions_for_stocks_with_open_holdings(): void
    {
        $user = User::query()->create([
            'name' => 'Scope User',
            'email' => 'scope-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $openStock = Stock::query()->create([
            'symbol' => 'OPEN1',
            'exchange' => 'NSE',
            'name' => 'Open Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $closedStock = Stock::query()->create([
            'symbol' => 'CLS1',
            'exchange' => 'NSE',
            'name' => 'Closed Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $openTx = Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $openStock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        $closedTxBuy = Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $closedStock->id,
            'type' => 'buy',
            'quantity' => 3,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2026-01-15',
        ]);

        $closedTxSell = Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $closedStock->id,
            'type' => 'sell',
            'quantity' => 3,
            'price' => 60,
            'fees' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        Holding::query()->create([
            'user_id' => $user->id,
            'stock_id' => $openStock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        Holding::query()->create([
            'user_id' => $user->id,
            'stock_id' => $closedStock->id,
            'quantity' => 0,
            'avg_buy_price' => 0,
            'invested_amount' => 0,
            'total_fees' => 0,
            'realized_profit' => 25,
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $openResponse = $this->getJson('/api/transactions?scope=open');
        $openResponse->assertOk();
        $openIds = collect($openResponse->json('data'))->pluck('id')->all();
        $this->assertSame([$openTx->id], $openIds);

        $closedResponse = $this->getJson('/api/transactions?scope=closed&per_page=25');
        $closedResponse->assertOk();
        $closedIds = collect($closedResponse->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$closedTxBuy->id, $closedTxSell->id],
            $closedIds,
        );
    }
}
