<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PortfolioLoggerService;
use App\Services\StockPriceHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StockPriceHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_range_when_no_local_data(): void
    {
        $stock = $this->makeStock('GAP');
        $service = $this->makeService();

        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('2024-01-01', $ranges[0]['from']->toDateString());
        $this->assertSame('2024-01-31', $ranges[0]['to']->toDateString());
    }

    public function test_no_missing_range_when_local_covers_required_period(): void
    {
        $stock = $this->makeStock('FULL');
$dates = [];
        for ($d = Carbon::parse('2024-01-01'); $d->lte(Carbon::parse('2024-04-01')); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $this->seedPrices($stock, $dates);

        $service = $this->makeService();
        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-03-01'),
        );

        $this->assertSame([], $ranges);
    }

    public function test_close_on_or_before_uses_previous_trading_day(): void
    {
        $stock = $this->makeStock('WEEK');
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-02-27',
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = $this->makeService();
        $close = $service->getCloseOnOrBeforeDate($stock, Carbon::parse('2026-02-28'));

        $this->assertSame(100.0, $close);
    }

    public function test_growth_and_relative_strength_calculation(): void
    {
        $stock = $this->makeStock('GRW');
        $benchmark = $this->makeStock('NIFTY50', true);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subMonths(1)->subDays(5)->toDateString(),
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDay()->toDateString(),
            'close_price' => 110,
            'adjusted_close_price' => 110,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => now()->subMonths(1)->subDays(5)->toDateString(),
            'close_price' => 200,
            'adjusted_close_price' => 200,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => now()->subDay()->toDateString(),
            'close_price' => 210,
            'adjusted_close_price' => 210,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = $this->makeService();
        $growth = $service->getGrowthPercentage($stock, 1);
        $rs = $service->getRelativeStrength($stock, $benchmark, 1);

        $this->assertSame(10.0, $growth);
        $this->assertSame(5.0, $rs);
    }

    public function test_normalized_gain_series_uses_one_year_anchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00', 'Asia/Kolkata'));

        $stock = $this->makeStock('NORM');
        $benchmark = $this->makeStock('NIFTY50', true);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-07-02',
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-07-01',
            'close_price' => 200,
            'adjusted_close_price' => 200,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => '2025-07-02',
            'close_price' => 1000,
            'adjusted_close_price' => 1000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => '2026-07-01',
            'close_price' => 1500,
            'adjusted_close_price' => 1500,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = $this->makeService();
        $series = $service->getNormalizedGainSeries(
            $stock,
            $benchmark,
            12,
            Carbon::parse('2026-07-02'),
        );

        Carbon::setTestNow();

        $this->assertCount(2, $series);
        $this->assertSame(0.0, $series[0]['stock_gain_percent']);
        $this->assertSame(0.0, $series[0]['benchmark_gain_percent']);
        $this->assertSame(100.0, $series[1]['stock_gain_percent']);
        $this->assertSame(50.0, $series[1]['benchmark_gain_percent']);
    }

    protected function makeStock(string $symbol, bool $benchmark = false): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol,
            'is_active' => true,
            'is_benchmark' => $benchmark,
        ]);
    }

    /**
     * @param  array<int, string>  $dates
     */
    protected function seedPrices(Stock $stock, array $dates): void
    {
        foreach ($dates as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'close_price' => 100,
                'adjusted_close_price' => 100,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }

    protected function makeService(): StockPriceHistoryService
    {
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('api')->andReturnNull();

        return new StockPriceHistoryService($logger);
    }
}
