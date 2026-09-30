<?php

namespace Tests\Feature\V8;

use App\Models\CalendarEvent;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Services\ML\MlHistoricalReferenceDateService;
use App\Support\TradingCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlHistoricalReferenceDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TradingCalendar::clearHolidayCache();
    }

    public function test_range_uses_market_history_and_excludes_weekends_and_trade_holidays(): void
    {
        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true,
        ]);
        foreach (['2026-01-02', '2026-01-03', '2026-01-05'] as $date) {
            StockPrice::query()->create([
                'stock_id' => $benchmark->id, 'price_date' => $date, 'close_price' => 100,
                'data_source' => 'test',
            ]);
        }
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Test holiday', 'anchor_date' => '2026-01-05',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE, 'is_active' => true,
        ]);

        $dates = app(MlHistoricalReferenceDateService::class)->datesBetween(
            \Carbon\Carbon::parse('2026-01-01'),
            \Carbon\Carbon::parse('2026-01-06'),
        );

        $this->assertSame(['2026-01-02'], $dates);
    }

    public function test_command_range_uses_only_resolved_market_dates(): void
    {
        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true,
        ]);
        $stock = Stock::query()->create([
            'symbol' => 'RANGE', 'exchange' => 'NSE', 'name' => 'Range Co', 'isin' => 'INE000000001',
        ]);
        foreach (['2026-01-02', '2026-01-03', '2026-01-05'] as $date) {
            StockPrice::query()->create(['stock_id' => $benchmark->id, 'price_date' => $date, 'close_price' => 100, 'data_source' => 'test']);
        }
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Test holiday', 'anchor_date' => '2026-01-05',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE, 'is_active' => true,
        ]);
        $path = storage_path('framework/testing/nse-20260102-'.bin2hex(random_bytes(4)).'.csv');
        File::put($path, "SYMBOL,SERIES,ISIN\nRANGE,EQ,INE000000001\n");

        $this->artisan('ml:backfill-nse-universe', [
            '--from' => '2026-01-01', '--to' => '2026-01-06', '--bhavcopy-path' => $path,
        ])->assertSuccessful();

        $this->assertSame(['2026-01-02'], MlUniverseSnapshotBackfillRun::query()->latest('id')->firstOrFail()->requested_dates);
        File::delete($path);
    }

    public function test_command_explicit_dates_are_not_calendar_filtered_or_expanded(): void
    {
        Stock::query()->create(['symbol' => 'EXPLICIT', 'exchange' => 'NSE', 'name' => 'Explicit Co', 'isin' => 'INE000000001']);
        $directory = storage_path('framework/testing/nse-explicit-'.bin2hex(random_bytes(4)));
        File::makeDirectory($directory);
        File::put($directory.'/nse-20260103.csv', "SYMBOL,SERIES,ISIN\nEXPLICIT,EQ,INE000000001\n");
        File::put($directory.'/nse-20260105.csv', "SYMBOL,SERIES,ISIN\nEXPLICIT,EQ,INE000000001\n");

        $this->artisan('ml:backfill-nse-universe', [
            '--dates' => '2026-01-03,2026-01-05', '--bhavcopy-path' => $directory,
        ])->assertSuccessful();

        $this->assertSame(['2026-01-03', '2026-01-05'], MlUniverseSnapshotBackfillRun::query()->latest('id')->firstOrFail()->requested_dates);
        File::deleteDirectory($directory);
    }
}
