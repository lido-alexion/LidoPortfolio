<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRealizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionSellRealizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_scope_exposes_fifo_realized_pl_and_fees_on_sells(): void
    {
        $user = User::query()->create([
            'name' => 'Sell Realization',
            'email' => 'sell-real-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'SELLR',
            'exchange' => 'NSE',
            'name' => 'Sell Realization Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 100,
            'fees' => 2,
            'transaction_date' => '2026-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 6,
            'price' => 120,
            'fees' => 7.2,
            'transaction_date' => '2026-01-02',
        ]);
        $sell = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 5,
            'price' => 150,
            'fees' => 7.5,
            'transaction_date' => '2026-01-03',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 3,
            'price' => 150,
            'fees' => 1,
            'transaction_date' => '2026-01-04',
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 0,
            'avg_buy_price' => 0,
            'invested_amount' => 0,
            'total_fees' => 0,
            'realized_profit' => 190,
            'updated_at' => now(),
        ]);

        app(TransactionRealizationService::class)->recalculateForProfileStock($profile, $stock);

        $this->actingAs($user);

        $closedResponse = $this->getJson('/api/transactions?scope=closed&per_page=25');
        $closedResponse->assertOk();
        $sellRow = collect($closedResponse->json('data'))->firstWhere('id', $sell->id);
        $this->assertNotNull($sellRow);
        $this->assertSame('190.0000', $sellRow['realized_pl']);
        $this->assertSame('13.1000', $sellRow['squared_off_fees']);
    }
}
