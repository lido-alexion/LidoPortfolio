<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\BseBhavcopyBackfillService;
use App\Services\BseBhavcopyService;
use App\Services\BseEquityMasterService;
use App\Services\EquityUniverseService;
use App\Services\PriceFetchService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use Tests\TestCase;

class BseBhavcopyBackfillServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_backfill_matches_bse_only_stocks_and_stores_rows(): void
    {
        $stock = new Stock([
            'id' => 42,
            'symbol' => 'TCS',
            'exchange' => 'BSE',
            'bse_scrip_code' => '532540',
        ]);

        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->with('exchange', 'BSE')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([$stock]));

        $equityUniverse = Mockery::mock(EquityUniverseService::class);
        $equityUniverse->shouldReceive('universeStockQuery')->andReturn($query);

        $bhavcopy = Mockery::mock(BseBhavcopyService::class);
        $bhavcopy->shouldReceive('eachEquityRowForDate')
            ->once()
            ->andReturnUsing(function ($date, callable $callback): void {
                $callback([
                'price_date' => '2025-07-10',
                'scrip_code' => '532540',
                'symbol' => 'TCS',
                'open_price' => 3500.0,
                'high_price' => 3520.0,
                'low_price' => 3490.0,
                'close_price' => 3510.0,
                'volume' => 12000,
                ]);
            });

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('storeHistoricalRows')
            ->once()
            ->with($stock, Mockery::type('array'), 'bse_bhavcopy')
            ->andReturn(1);

        $service = new BseBhavcopyBackfillService(
            $bhavcopy,
            Mockery::mock(BseEquityMasterService::class),
            $equityUniverse,
            $priceFetch,
        );

        $stats = $service->backfill(
            Carbon::parse('2025-07-10'),
            Carbon::parse('2025-07-10'),
            null,
            false,
        );

        $this->assertSame(1, $stats['stocks']);
        $this->assertSame(1, $stats['days_processed']);
        $this->assertSame(1, $stats['rows_matched']);
        $this->assertSame(1, $stats['rows_stored']);
    }
}
