<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlPatternFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pattern_features_populate_when_ohlc_available(): void
    {
        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'name' => 'NIFTY 50',
            'is_benchmark' => true,
            'is_active' => true,
        ]);
        $stock = Stock::query()->create([
            'symbol' => 'PAT',
            'exchange' => 'NSE',
            'name' => 'Pattern Stock',
            'is_active' => true,
        ]);

        for ($day = 0; $day < 280; $day++) {
            $date = Carbon::parse('2024-01-01')->addDays($day)->toDateString();
            $close = 100 + $day * 0.05;
            foreach ([$benchmark, $stock] as $subject) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => $date,
                    'open_price' => $close - 0.5,
                    'high_price' => $close + 1.5,
                    'low_price' => $close - 1.5,
                    'close_price' => $close,
                    'adjusted_close_price' => $close,
                    'volume' => 2000 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $this->assertCount(49, MlTrainingDatasetBuilder::NUMERIC_FEATURES);
        $features = app(MlTrainingDatasetBuilder::class)->featuresFor($stock, Carbon::parse('2024-09-01'));
        $this->assertNotNull($features['atr_pct_14'] ?? null);
        $this->assertNotNull($features['range_position_20d'] ?? null);
        $this->assertNotNull($features['up_day_ratio_20d'] ?? null);
    }
}
