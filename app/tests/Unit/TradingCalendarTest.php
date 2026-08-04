<?php

namespace Tests\Unit;

use App\Models\CalendarEvent;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_last_required_price_session_on_session_day_is_prior_session(): void
    {
        Carbon::setTestNow('2026-07-10 13:39:00');

        $session = TradingCalendar::lastRequiredPriceSession();

        $this->assertSame('2026-07-09', $session->toDateString());
    }

    public function test_last_required_price_session_on_weekend_uses_latest_weekday(): void
    {
        Carbon::setTestNow('2026-07-11 10:00:00');

        $session = TradingCalendar::lastRequiredPriceSession();

        $this->assertSame('2026-07-10', $session->toDateString());
    }

    public function test_last_required_price_session_on_monday_uses_prior_friday(): void
    {
        Carbon::setTestNow('2026-07-13 09:00:00');

        $session = TradingCalendar::lastRequiredPriceSession();

        $this->assertSame('2026-07-10', $session->toDateString());
    }

    public function test_trade_holiday_is_not_equity_session(): void
    {
        CalendarEvent::query()->create([
            'profile_id' => null,
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Diwali',
            'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-11-09',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false,
            'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();

        $this->assertTrue(TradingCalendar::isTradeHoliday(Carbon::parse('2026-11-09')));
        $this->assertFalse(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-11-09')));
        $this->assertTrue(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-11-10')));
    }

    public function test_weekend_is_not_equity_session(): void
    {
        $this->assertFalse(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-08-01')));
        $this->assertFalse(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-08-02')));
        $this->assertTrue(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-08-03')));
    }
}
