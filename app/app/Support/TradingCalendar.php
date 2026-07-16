<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * NSE/BSE equity session calendar (weekdays only; exchange holidays not modeled yet).
 */
class TradingCalendar
{
    public static function isEquitySessionDate(Carbon $date): bool
    {
        return ! $date->copy()->startOfDay()->isWeekend();
    }

    /**
     * Walk backward to the latest weekday on or before $date.
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
