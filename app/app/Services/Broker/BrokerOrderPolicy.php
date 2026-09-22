<?php

namespace App\Services\Broker;

use App\Exceptions\DomainException;
use App\Support\TradingCalendar;
use Carbon\Carbon;

final class BrokerOrderPolicy
{
    public const REGULAR = 'regular';
    public const AMO = 'amo';

    public function variety(?Carbon $at = null): string
    {
        $time = ($at ?? now())->copy()->timezone('Asia/Kolkata');
        if (! TradingCalendar::isEquitySessionDate($time)) {
            throw new DomainException('Broker orders are blocked outside an equity session.', 'BROKER_ORDER_WINDOW_UNSUPPORTED', 422);
        }

        $minutes = ((int) $time->format('H')) * 60 + (int) $time->format('i');
        if ($minutes >= 555 && $minutes <= 930) { // 09:15 through 15:30 IST.
            return self::REGULAR;
        }
        if ($minutes >= 960 || $minutes < 540) { // Kite equity AMO window.
            return self::AMO;
        }

        throw new DomainException('Broker order window is not safely supported at this time.', 'BROKER_ORDER_WINDOW_UNSUPPORTED', 422);
    }
}
