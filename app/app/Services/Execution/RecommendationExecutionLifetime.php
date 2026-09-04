<?php

namespace App\Services\Execution;

use App\Support\TradingCalendar;
use Carbon\Carbon;

/** Frozen FEAT-039 two-session lifetime derivation. */
class RecommendationExecutionLifetime
{
    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * @return array{anchor_class:string,anchor_date:string,first_eligible_date:string,second_eligible_date:string,expires_at:Carbon}
     */
    public function derive(Carbon $generatedAt, string $cutoff): array
    {
        $generated = $generatedAt->copy()->timezone(self::TIMEZONE);
        $day = $generated->copy()->startOfDay();
        $cutoffAt = $day->copy()->setTimeFromTimeString($cutoff);
        $isDayZero = TradingCalendar::isEquitySessionDate($day) && $generated->lt($cutoffAt);

        $first = $isDayZero
            ? $day->copy()
            : TradingCalendar::nextSessionOnOrAfter($day->copy()->addDay());
        $second = TradingCalendar::addSessions($first, 1);

        return [
            'anchor_class' => $isDayZero ? 'day_0' : 'day_1',
            'anchor_date' => $day->toDateString(),
            'first_eligible_date' => $first->toDateString(),
            'second_eligible_date' => $second->toDateString(),
            'expires_at' => $second->copy()->setTimeFromTimeString($cutoff),
        ];
    }
}
