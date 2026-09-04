<?php

namespace Tests\Unit\Execution;

use App\Models\CalendarEvent;
use App\Services\Execution\RecommendationExecutionLifetime;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationExecutionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_before_cutoff_session_is_day_zero_and_gets_two_opportunities(): void
    {
        $result = app(RecommendationExecutionLifetime::class)->derive(
            Carbon::parse('2026-09-04 09:00:00', 'Asia/Kolkata'),
            '15:30',
        );

        $this->assertSame('day_0', $result['anchor_class']);
        $this->assertSame('2026-09-04', $result['first_eligible_date']);
        $this->assertSame('2026-09-07', $result['second_eligible_date']);
        $this->assertSame('2026-09-07 15:30:00', $result['expires_at']->format('Y-m-d H:i:s'));
    }

    public function test_after_cutoff_skips_weekends_and_trade_holidays(): void
    {
        CalendarEvent::query()->create([
            'title' => 'Trade holiday',
            'anchor_date' => '2026-09-07',
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();

        $result = app(RecommendationExecutionLifetime::class)->derive(
            Carbon::parse('2026-09-04 16:00:00', 'Asia/Kolkata'),
            '15:30',
        );

        $this->assertSame('day_1', $result['anchor_class']);
        $this->assertSame('2026-09-08', $result['first_eligible_date']);
        $this->assertSame('2026-09-09', $result['second_eligible_date']);
    }
}
