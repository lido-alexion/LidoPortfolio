<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\CorporateActionPriceAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorporateActionPriceAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_restates_prices_before_ex_date(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'ADJ1',
            'exchange' => 'NSE',
            'name' => 'Adjust Split',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->seedPrice($stock->id, '2026-01-10', 100, 1000);
        $this->seedPrice($stock->id, '2026-02-10', 120, 800);
        $this->seedPrice($stock->id, '2026-03-01', 58, 2200);

        $service = app(CorporateActionPriceAdjustmentService::class);
        $result = $service->adjustHistoricalPrices($stock, '2026-03-01', 'split', 1, 2);

        $this->assertSame(2, $result['rows_adjusted']);
        $this->assertEquals(50.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
        $this->assertEquals(60.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-02-10')->value('close_price'));
        $this->assertEquals(2000, (int) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('volume'));
        $this->assertEquals(58.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-03-01')->value('close_price'));
    }

    public function test_bonus_restates_prices_before_ex_date(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'ADJ2',
            'exchange' => 'NSE',
            'name' => 'Adjust Bonus',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->seedPrice($stock->id, '2026-01-10', 200, 500);
        $this->seedPrice($stock->id, '2026-03-01', 95, 900);

        $service = app(CorporateActionPriceAdjustmentService::class);
        $result = $service->adjustHistoricalPrices($stock, '2026-03-01', 'bonus', 1, 1);

        $this->assertSame(1, $result['rows_adjusted']);
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
        $this->assertEquals(1000, (int) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('volume'));
        $this->assertEquals(95.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-03-01')->value('close_price'));
    }

    protected function seedPrice(int $stockId, string $date, float $close, int $volume): void
    {
        StockPrice::query()->create([
            'stock_id' => $stockId,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'adjusted_close_price' => $close,
            'volume' => $volume,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }
}
