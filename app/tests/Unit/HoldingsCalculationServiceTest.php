<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HoldingsCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HoldingsCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_avg_buy_and_invested_exclude_fees_and_total_fees_tracks_position_fees(): void
    {
        $user = User::query()->create([
            'name' => 'Fees User',
            'email' => 'fees-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'FEES',
            'exchange' => 'NSE',
            'name' => 'Fees Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 10,
            'transaction_date' => '2026-01-10',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 4,
            'price' => 120,
            'fees' => 5,
            'transaction_date' => '2026-02-01',
        ]);

        $holding = app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);

        $this->assertEqualsWithDelta(6, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(100, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(600, (float) $holding->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(15, (float) $holding->total_fees, 0.0001);
        $this->assertEqualsWithDelta(75, (float) $holding->realized_profit, 0.0001);
    }
}
