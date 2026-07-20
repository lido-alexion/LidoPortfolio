<?php

namespace Tests\Unit\Screener;

use App\Services\Screener\ScreenerBacktestService;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\ScreenerRunService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class ScreenerBacktestCalendarTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_weekday_dates_skip_weekends(): void
    {
        $service = new ScreenerBacktestService(
            Mockery::mock(ScreenerEvaluationService::class),
            Mockery::mock(ScreenerRunService::class),
        );

        $from = Carbon::parse('2026-07-10', 'UTC'); // Friday
        $to = Carbon::parse('2026-07-14', 'UTC'); // Tuesday
        $dates = $service->weekdayDates($from, $to);

        $this->assertSame(
            ['2026-07-10', '2026-07-13', '2026-07-14'],
            array_map(static fn (Carbon $d) => $d->toDateString(), $dates),
        );
    }

    public function test_from_date_for_ranges(): void
    {
        $service = new ScreenerBacktestService(
            Mockery::mock(ScreenerEvaluationService::class),
            Mockery::mock(ScreenerRunService::class),
        );
        $to = Carbon::parse('2026-07-21', 'UTC');

        $this->assertSame('2025-07-21', $service->fromDateForRange('1y', $to)->toDateString());
        $this->assertSame('2026-01-21', $service->fromDateForRange('6m', $to)->toDateString());
        $this->assertSame('2026-04-21', $service->fromDateForRange('3m', $to)->toDateString());
        $this->assertSame('2026-06-21', $service->fromDateForRange('1m', $to)->toDateString());
        $this->assertSame('2026-07-07', $service->fromDateForRange('15d', $to)->toDateString());
    }
}
