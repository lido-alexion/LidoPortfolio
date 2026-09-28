<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlVolumeVolatilityFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_features_for_include_volume_trend_and_volatility_ratio(): void
    {
        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'name' => 'NIFTY 50',
            'is_benchmark' => true,
            'is_active' => true,
        ]);
        $stock = Stock::query()->create([
            'symbol' => 'VOL',
            'exchange' => 'NSE',
            'name' => 'Vol Stock',
            'is_active' => true,
        ]);

        for ($day = 0; $day < 280; $day++) {
            $date = Carbon::parse('2024-01-01')->addDays($day)->toDateString();
            foreach ([$benchmark, $stock] as $subject) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => $date,
                    'close_price' => 100 + sin($day / 8) * 5 + $day * 0.01,
                    'adjusted_close_price' => 100 + $day * 0.01,
                    'volume' => 1000 + ($subject->id === $stock->id ? $day * 10 : 500),
                    'data_source' => 'test',
                ]);
            }
        }

        $features = app(MlTrainingDatasetBuilder::class)->featuresFor($stock, Carbon::parse('2024-09-01'));
        $this->assertContains('volatility_ratio_20_63', MlTrainingDatasetBuilder::NUMERIC_FEATURES);
        $this->assertContains('volume_trend_20d', MlTrainingDatasetBuilder::NUMERIC_FEATURES);
        $this->assertNotNull($features['volatility_ratio_20_63'] ?? null);
        $this->assertNotNull($features['volume_trend_20d'] ?? null);
    }
}
