<?php

namespace App\Support;

use App\Models\CalendarEvent;
use App\Services\CalendarRecurrenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * NSE/BSE equity session calendar (weekdays + admin-defined trade holidays).
 */
class TradingCalendar
{
    /** @var array<string, bool> */
    protected static array $holidayCache = [];

    public static function clearHolidayCache(): void
    {
        self::$holidayCache = [];
    }

    public static function isEquitySessionDate(Carbon $date): bool
    {
        $day = $date->copy()->startOfDay();

        if ($day->isWeekend()) {
            return false;
        }

        return ! self::isTradeHoliday($day);
    }

    /**
     * Whether scheduled market-data jobs (daily sync, benchmark/index prices) should run today.
     * Uses cron timezone from settings when available.
     */
    public static function isScheduledMarketDataDay(?Carbon $at = null, ?string $timezone = null): bool
    {
        $tz = $timezone;
        if ($tz === null || trim($tz) === '') {
            try {
                $tz = app(\App\Services\SettingsService::class)->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
            } catch (\Throwable) {
                $tz = 'Asia/Kolkata';
            }
        }

        $day = ($at ?? now())->timezone($tz);

        return self::isEquitySessionDate($day);
    }

    /**
     * True when an active global trade-holiday calendar event occurs on this date.
     */
    public static function isTradeHoliday(?Carbon $date = null): bool
    {
        $day = ($date ?? now())->copy()->startOfDay();
        $key = $day->toDateString();

        if (array_key_exists($key, self::$holidayCache)) {
            return self::$holidayCache[$key];
        }

        $holidays = self::tradeHolidayDatesBetween($day, $day);
        self::$holidayCache[$key] = isset($holidays[$key]);

        return self::$holidayCache[$key];
    }

    /**
     * @return array<string, true> Map of Y-m-d => true for trade-holiday dates in range
     */
    public static function tradeHolidayDatesBetween(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('portfolio_calendar_events')
            || ! Schema::hasColumn('portfolio_calendar_events', 'category')) {
            return [];
        }

        $events = CalendarEvent::query()
            ->whereNull('profile_id')
            ->where('category', CalendarEvent::CATEGORY_TRADE_HOLIDAY)
            ->where('is_active', true)
            ->get();

        if ($events->isEmpty()) {
            return [];
        }

        $dates = [];
        $recurrence = app(CalendarRecurrenceService::class);
        foreach ($recurrence->occurrencesForEvents($events, $from->copy()->startOfDay(), $to->copy()->endOfDay()) as $occurrence) {
            $dates[$occurrence['date']] = true;
        }

        return $dates;
    }

    /**
     * Walk backward to the latest weekday (non-holiday) on or before $date.
     */
    public static function normalizeToSessionDate(Carbon $date): Carbon
    {
        $session = $date->copy()->startOfDay();

        while (! self::isEquitySessionDate($session)) {
            $session->subDay();
        }

        return $session;
    }

    /**
     * Latest equity session date we require cached OHLCV through for gap scans.
     * On a session day, today's EOD bar is excluded until nightly sync — avoids
     * flagging every symbol as "missing today" during the trading day.
     */
    public static function lastRequiredPriceSession(?Carbon $asOf = null): Carbon
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $session = self::normalizeToSessionDate($asOf);

        if ($session->equalTo($asOf)) {
            return self::normalizeToSessionDate($asOf->copy()->subDay());
        }

        return $session;
    }
}
