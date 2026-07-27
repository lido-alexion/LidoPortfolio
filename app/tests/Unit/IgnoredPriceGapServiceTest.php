<?php

namespace Tests\Unit;

use App\Models\IgnoredPriceGap;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\IgnoredPriceGapService;
use App\Services\StockPriceHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IgnoredPriceGapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ignored_gap_is_excluded_from_missing_history_ranges(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.history_days' => 60,
            'portfolio.history.max_internal_gap_days' => 7,
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'IGNOREME',
            'exchange' => 'BSE',
            'name' => 'Ignore Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        foreach (['2026-04-01', '2026-04-20'] as $date) {
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

        $history = app(StockPriceHistoryService::class);
        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2026-06-20');

        $before = $history->getMissingHistoryRanges($stock, $from, $to);
        $this->assertNotEmpty($before);
        // Internal hole (Apr 2–19) plus trailing suffix through requiredTo.
        $this->assertGreaterThanOrEqual(2, count($before));

        foreach ($before as $range) {
            app(IgnoredPriceGapService::class)->ignore(
                $stock->id,
                $range['from']->toDateString(),
                $range['to']->toDateString(),
            );
        }

        $after = $history->getMissingHistoryRanges($stock, $from, $to);
        $this->assertSame([], $after);
        $this->assertSame(count($before), IgnoredPriceGap::query()->count());
    }
}
