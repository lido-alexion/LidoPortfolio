<?php

namespace App\Services\Entry;

use Carbon\CarbonInterface;

/**
 * OD-11 / §11.2 — 1 calendar-day BUY cooldown.
 *
 * Key: (stock, strategy). Clock starts on BUY recommendation opportunity
 * (generation), not fill/approval. Day 0 allowed; Day 1 suppressed; Day 2 elapsed.
 */
final class BuyCooldownEvaluator
{
    public const COOLDOWN_CALENDAR_DAYS = 1;

    /**
     * True when a new OPEN/INCREASE must be suppressed for the pair.
     *
     * @param  CarbonInterface|null  $lastBuyOpportunityDate  Calendar date of the last OPEN/INCREASE generation for the pair
     * @param  CarbonInterface  $asOf  Evaluation calendar date (typically "today")
     */
    public function isActive(?CarbonInterface $lastBuyOpportunityDate, CarbonInterface $asOf): bool
    {
        if ($lastBuyOpportunityDate === null) {
            return false;
        }

        $last = $lastBuyOpportunityDate->copy()->startOfDay();
        $today = $asOf->copy()->startOfDay();

        // Eligible again on last + 2 calendar days (Day 2).
        $elapsed = (int) $last->diffInDays($today, false);

        return $elapsed >= 0 && $elapsed <= self::COOLDOWN_CALENDAR_DAYS;
    }

    /**
     * First calendar date on which a new BUY may be generated (last + 2 days).
     */
    public function nextEligibleDate(CarbonInterface $lastBuyOpportunityDate): CarbonInterface
    {
        return $lastBuyOpportunityDate->copy()->startOfDay()->addDays(self::COOLDOWN_CALENDAR_DAYS + 1);
    }
}
