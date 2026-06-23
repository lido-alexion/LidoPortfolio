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
}
