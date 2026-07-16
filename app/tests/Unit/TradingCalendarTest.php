<?php

namespace Tests\Unit;

use App\Support\TradingCalendar;
use Carbon\Carbon;
use Tests\TestCase;

class TradingCalendarTest extends TestCase
{
    public function test_last_required_price_session_on_session_day_is_prior_session(): void
    {
        Carbon::setTestNow('2026-07-10 13:39:00');

        $session = TradingCalendar::lastRequiredPriceSession();

        $this->assertSame('2026-07-09', $session->toDateString());

        Carbon::setTestNow();
    }

    public function test_last_required_price_session_on_weekend_uses_latest_weekday(): void
    {
        Carbon::setTestNow('2026-07-11 10:00:00');

        $session = TradingCalendar::lastRequiredPriceSession();

        $this->assertSame('2026-07-10', $session->toDateString());

        Carbon::setTestNow();
    }

    public function test_last_required_price_session_on_monday_uses_prior_friday(): void
    {
        Carbon::setTestNow('2026-07-13 09:00:00');

        $session = TradingCalendar::lastRequiredPriceSession();

        $this->assertSame('2026-07-10', $session->toDateString());

        Carbon::setTestNow();
    }
}
