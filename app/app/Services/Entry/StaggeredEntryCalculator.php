<?php

namespace App\Services\Entry;

/**
 * OD-12 / §12 — staggered entry sizing (pure).
 *
 * First entry = first_entry_pct × current_target_amount (default 50%).
 * Subsequent = remaining = max(0, current_target − filled). Not a second 50% tranche.
 */
final class StaggeredEntryCalculator
{
    public const DEFAULT_FIRST_ENTRY_PCT = 50.0;

    public function normalizeFirstEntryPct(?float $configuredPct): float
    {
        if ($configuredPct === null || $configuredPct <= 0 || $configuredPct > 100) {
            return self::DEFAULT_FIRST_ENTRY_PCT;
        }

        return (float) $configuredPct;
    }

    public function remainingAmount(float $currentTargetAmount, float $filledAmount): float
    {
        return round(max(0.0, $currentTargetAmount - max(0.0, $filledAmount)), 4);
    }

    public function firstEntryAmount(float $currentTargetAmount, ?float $firstEntryPct = null): float
    {
        $pct = $this->normalizeFirstEntryPct($firstEntryPct);

        return round(max(0.0, $currentTargetAmount) * ($pct / 100.0), 4);
    }

    /**
     * This-cycle intended amount before whole-share conversion / min-actionable gate.
     *
     * @return array{
     *     this_cycle_amount: float,
     *     remaining_amount: float,
     *     is_first_entry: bool,
     *     first_entry_pct: float
     * }
     */
    public function thisCycleIntendedAmount(
        float $currentTargetAmount,
        float $filledAmount,
        bool $hasOpenPosition,
        ?float $firstEntryPct = null,
    ): array {
        $pct = $this->normalizeFirstEntryPct($firstEntryPct);
        $remaining = $this->remainingAmount($currentTargetAmount, $filledAmount);

        if (! $hasOpenPosition) {
            $first = $this->firstEntryAmount($currentTargetAmount, $pct);

            return [
                'this_cycle_amount' => $first,
                'remaining_amount' => $remaining,
                'is_first_entry' => true,
                'first_entry_pct' => $pct,
            ];
        }

        return [
            'this_cycle_amount' => $remaining,
            'remaining_amount' => $remaining,
            'is_first_entry' => false,
            'first_entry_pct' => $pct,
        ];
    }
}
