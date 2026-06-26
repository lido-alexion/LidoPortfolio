<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HoldingPresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HoldingPresentationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_highest_close_since_buy_uses_prices_from_first_buy_in_current_position(): void
    {
        $service = app(HoldingPresentationService::class);

        $user = User::query()->create([
            'name' => 'Price User',
            'email' => 'price-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'P'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Price Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2024-01-10',
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-01-09',
            'open_price' => 90,
            'high_price' => 95,
            'low_price' => 88,
            'close_price' => 92,
            'volume' => 100,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-01-15',
            'open_price' => 100,
            'high_price' => 130,
            'low_price' => 99,
            'close_price' => 125,
            'volume' => 100,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $firstBuy = $service->firstBuyDateForCurrentPosition($profile, $stock);
        $this->assertEquals('2024-01-10', $firstBuy->toDateString());

        $holding = $profile->holdings()->create([
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 100,
            'invested_amount' => 100,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
        $holding->setRelation('stock', $stock);

        $enriched = $service->enrichHolding($profile, $holding);
        $summary = $enriched['stoploss_summary'];

        $this->assertSame(125.0, (float) $summary['highest_close_since_buy']);
        $this->assertSame('2024-01-15', $summary['highest_close_since_buy_date']);
        $this->assertSame(112.5, (float) $summary['trailing_stop_price']);
        $this->assertTrue($summary['has_price_history']);
    }

    public function test_first_buy_date_resets_after_full_exit_and_rebuy(): void
    {
        $service = app(HoldingPresentationService::class);

        $user = User::query()->create([
            'name' => 'Rebuy User',
            'email' => 'rebuy-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'R'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Rebuy Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 900,
            'fees' => 0,
            'transaction_date' => '2024-01-05',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 1,
            'price' => 1000,
            'fees' => 0,
            'transaction_date' => '2024-03-01',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 500,
            'fees' => 0,
            'transaction_date' => '2025-06-01',
        ]);

        $firstBuy = $service->firstBuyDateForCurrentPosition($profile, $stock);
        $this->assertEquals('2025-06-01', $firstBuy->toDateString());
    }
}


