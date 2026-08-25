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
        // OD-22 default portfolio trailing 15%: 125 × 0.85 = 106.25 (not SL% 10%)
        $this->assertSame(106.25, (float) $summary['trailing_stop_price']);
        $this->assertEqualsWithDelta(15.0, (float) $summary['portfolio_trailing_percent'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $summary['weighted_average_fill_cost'], 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $summary['stop_loss_price'], 0.0001); // 10% of 100
        $this->assertTrue($summary['has_price_history']);
    }

    public function test_enrich_holding_includes_unrealized_and_daily_change_percent(): void
    {
        $service = app(HoldingPresentationService::class);

        $user = User::query()->create([
            'name' => 'Unrealized User',
            'email' => 'unreal-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'U'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Unrealized Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2024-01-10',
        ]);

        foreach ([
            ['2024-01-14', 100],
            ['2024-01-15', 110],
        ] as [$date, $close]) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'volume' => 100,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $holding = $profile->holdings()->create([
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
        $holding->setRelation('stock', $stock);

        $enriched = $service->enrichHolding($profile, $holding);

        $this->assertEqualsWithDelta(100.0, (float) $enriched['unrealized_profit'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $enriched['unrealized_gain_percent'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $enriched['stoploss_summary']['daily_change_percent'], 0.001);
        $this->assertSame('2024-01-14', $enriched['stoploss_summary']['previous_price_date']);
    }

    public function test_daily_change_percent_null_with_single_price_row(): void
    {
        $service = app(HoldingPresentationService::class);

        $user = User::query()->create([
            'name' => 'Single Price User',
            'email' => 'single-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'S'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Single Price',
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
            'price_date' => '2024-01-15',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 100,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

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

        $this->assertNull($enriched['stoploss_summary']['daily_change_percent']);
        $this->assertNull($enriched['stoploss_summary']['previous_price_date']);
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

    public function test_price_history_all_and_since_buy_are_distinct(): void
    {
        $service = app(HoldingPresentationService::class);

        $user = User::query()->create([
            'name' => 'History Range User',
            'email' => 'history-range-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'H'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'History Range',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        // 3+ years of available market history.
        foreach ([
            '2023-01-03', '2023-07-03', '2024-01-03', '2024-07-03', '2025-01-03', '2025-07-03', '2026-01-03',
        ] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => 100,
                'high_price' => 101,
                'low_price' => 99,
                'close_price' => 100,
                'volume' => 100,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        // Holding entered recently.
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-03',
        ]);

        $all = $service->priceHistoryForHolding($profile, $stock, 'all');
        $sinceBuy = $service->priceHistoryForHolding($profile, $stock, 'since_buy');

        $this->assertSame('all', $all['range']);
        $this->assertSame('since_buy', $sinceBuy['range']);
        $this->assertSame('2023-01-03', $all['from_date']);
        $this->assertSame('2026-01-03', $sinceBuy['from_date']);
        $this->assertGreaterThan($sinceBuy['price_count'], $all['price_count']);
        $this->assertSame(7, $all['all_price_count']);
        $this->assertSame(1, $all['since_buy_price_count']);
    }
}


