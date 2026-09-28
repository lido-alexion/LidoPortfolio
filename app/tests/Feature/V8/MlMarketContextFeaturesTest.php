<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlMarketBreadthFeatureService;
use App\Services\ML\MlSectorRelativeStrengthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlMarketContextFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_breadth_counts_stocks_above_sma20(): void
    {
        $stocks = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $symbol) {
            $stocks[] = Stock::query()->create([
                'symbol' => $symbol,
                'exchange' => 'NSE',
                'name' => $symbol,
                'is_active' => true,
            ]);
        }

        foreach ($stocks as $stock) {
            MlUniverseMembership::query()->create([
                'stock_id' => $stock->id,
                'universe_key' => 'active_eligible_nse',
                'effective_from' => '2025-01-01',
                'sector_snapshot' => 'General',
                'source' => 'test',
            ]);
        }

        $asOf = '2025-06-30';
        foreach ($stocks as $index => $stock) {
            for ($day = 0; $day < 25; $day++) {
                $date = Carbon::parse('2025-06-01')->addDays($day)->toDateString();
                StockPrice::query()->create([
                    'stock_id' => $stock->id,
                    'price_date' => $date,
                    'close_price' => 100 + $day + ($index < 3 ? 5 : 0),
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $breadth = app(MlMarketBreadthFeatureService::class)->pctAboveSma20($asOf);
        $this->assertNotNull($breadth);
        $this->assertGreaterThanOrEqual(0, $breadth);
        $this->assertLessThanOrEqual(100, $breadth);
    }

    public function test_sector_relative_strength_uses_peer_median(): void
    {
        $leader = Stock::query()->create([
            'symbol' => 'LEAD',
            'exchange' => 'NSE',
            'name' => 'Leader',
            'sector' => 'Tech',
            'is_active' => true,
        ]);
        $laggard = Stock::query()->create([
            'symbol' => 'LAG',
            'exchange' => 'NSE',
            'name' => 'Laggard',
            'sector' => 'Tech',
            'is_active' => true,
        ]);

        foreach ([$leader, $laggard] as $index => $stock) {
            MlUniverseMembership::query()->create([
                'stock_id' => $stock->id,
                'universe_key' => 'active_eligible_nse',
                'effective_from' => '2025-01-01',
                'sector_snapshot' => 'Tech',
                'source' => 'test',
            ]);
            for ($day = 0; $day < 80; $day++) {
                StockPrice::query()->create([
                    'stock_id' => $stock->id,
                    'price_date' => Carbon::parse('2025-04-01')->addDays($day)->toDateString(),
                    'close_price' => 100 + ($index === 0 ? $day * 2 : $day),
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $relative = app(MlSectorRelativeStrengthService::class)->relativeStrength3m($leader, '2025-06-20');
        $this->assertNotNull($relative);
        $this->assertGreaterThan(0, $relative);
    }

    public function test_context_is_unavailable_without_a_point_in_time_membership_snapshot(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'CURRENT',
            'exchange' => 'NSE',
            'name' => 'Current only',
            'sector' => 'Tech',
            'is_active' => true,
        ]);

        for ($day = 0; $day < 25; $day++) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => Carbon::parse('2025-06-01')->addDays($day)->toDateString(),
                'close_price' => 100 + $day,
                'adjusted_close_price' => 100 + $day,
                'data_source' => 'test',
            ]);
        }

        $this->assertNull(app(MlMarketBreadthFeatureService::class)->pctAboveSma20('2025-06-30'));
        $this->assertNull(app(MlSectorRelativeStrengthService::class)->relativeStrength3m($stock, '2025-06-30'));
    }
}
