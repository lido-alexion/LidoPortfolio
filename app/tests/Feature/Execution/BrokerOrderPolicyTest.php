<?php

namespace Tests\Feature\Execution;

use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\Broker\BrokerOrderPolicy;
use App\Support\TradingCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BrokerOrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_orders_are_used_during_the_nse_session(): void
    {
        $this->assertSame(
            BrokerOrderPolicy::REGULAR,
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 10:00:00', 'Asia/Kolkata')),
        );
    }

    public function test_amo_orders_are_used_in_the_supported_after_market_window(): void
    {
        $this->assertSame(
            BrokerOrderPolicy::AMO,
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 18:00:00', 'Asia/Kolkata')),
        );
    }

    public function test_before_market_open_is_amo(): void
    {
        $this->assertSame(BrokerOrderPolicy::AMO, app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 09:05:00', 'Asia/Kolkata')));
    }

    public function test_weekend_is_amo_even_when_clock_is_inside_market_hours(): void
    {
        $this->assertSame(BrokerOrderPolicy::AMO, app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-26 10:00:00', 'Asia/Kolkata')));
    }

    public function test_exchange_holiday_is_amo_even_when_clock_is_inside_market_hours(): void
    {
        CalendarEvent::query()->create([
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'source' => 'test',
            'title' => 'Exchange holiday',
            'anchor_date' => '2026-09-23',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();

        $this->assertSame(BrokerOrderPolicy::AMO, app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-23 10:00:00', 'Asia/Kolkata')));
    }

    public function test_configured_boundaries_are_inclusive(): void
    {
        $policy = app(BrokerOrderPolicy::class);

        $this->assertSame(BrokerOrderPolicy::REGULAR, $policy->variety(Carbon::parse('2026-09-22 09:15:00', 'Asia/Kolkata')));
        $this->assertSame(BrokerOrderPolicy::REGULAR, $policy->variety(Carbon::parse('2026-09-22 15:30:00', 'Asia/Kolkata')));
        $this->assertSame(BrokerOrderPolicy::AMO, $policy->variety(Carbon::parse('2026-09-22 15:31:00', 'Asia/Kolkata')));
    }

    public function test_custom_market_hours_are_read_from_settings(): void
    {
        Setting::setValue('market_open_time', '10:00');
        Setting::setValue('market_close_time', '14:00');
        $policy = app(BrokerOrderPolicy::class);

        $this->assertSame(BrokerOrderPolicy::REGULAR, $policy->variety(Carbon::parse('2026-09-22 10:30:00', 'Asia/Kolkata')));
        $this->assertSame(BrokerOrderPolicy::AMO, $policy->variety(Carbon::parse('2026-09-22 09:30:00', 'Asia/Kolkata')));
        $this->assertSame(BrokerOrderPolicy::AMO, $policy->variety(Carbon::parse('2026-09-22 14:30:00', 'Asia/Kolkata')));
    }

    public function test_configured_platform_timezone_is_used(): void
    {
        Setting::setValue('cron_timezone', 'UTC');

        $this->assertSame(
            BrokerOrderPolicy::AMO,
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 08:00:00', 'UTC')),
        );
    }
}
