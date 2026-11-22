<?php

namespace App\Support;

use Carbon\Carbon;

final class KiteTradingSession
{
    public static function isEquityMarketOpen(?Carbon $at = null): bool
    {
        $local = ($at ?? now())->copy()->timezone('Asia/Kolkata');
        if (! TradingCalendar::isEquitySessionDate($local)) {
            return false;
        }
        $minutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        return $minutes >= (9 * 60 + 15) && $minutes <= (15 * 60 + 30);
    }
}
