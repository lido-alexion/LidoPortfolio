<?php

namespace App\Services\ML;

use Carbon\Carbon;

/**
 * Defines the minimum primary-benchmark window for V7 chronological ML data.
 *
 * The policy is deliberately expressed in sampling buckets plus observation
 * overhead, rather than as an arbitrary number of days.  Forty monthly
 * reference buckets provide roughly 28/6/6 train/validation/test buckets;
 * nine calendar months conservatively cover the 63-observation feature
 * lookback plus the 126-observation maximum label horizon.
 */
class MlBenchmarkHistoryPolicy
{
    public const VERSION = 'v7-primary-benchmark-history-1';

    public const FEATURE_LOOKBACK_OBSERVATIONS = 63;

    public const MAX_LABEL_OBSERVATIONS = 126;

    public const MINIMUM_REFERENCE_BUCKETS = 40;

    public const TRADING_OBSERVATIONS_PER_MONTH = 21;

    public function requiredCalendarMonths(): int
    {
        return self::MINIMUM_REFERENCE_BUCKETS + (int) ceil(
            (self::FEATURE_LOOKBACK_OBSERVATIONS + self::MAX_LABEL_OBSERVATIONS)
            / self::TRADING_OBSERVATIONS_PER_MONTH,
        );
    }

    /**
     * @return array{from: Carbon, to: Carbon, policy: array<string,mixed>}
     */
    public function requiredRange(Carbon $to): array
    {
        $to = $to->copy()->startOfDay();
        $months = $this->requiredCalendarMonths();

        return [
            'from' => $to->copy()->subMonths($months)->startOfMonth(),
            'to' => $to,
            'policy' => $this->definition(),
        ];
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'version' => self::VERSION,
            'sampling' => 'monthly_reference_bucket',
            'minimum_reference_buckets' => self::MINIMUM_REFERENCE_BUCKETS,
            'feature_lookback_observations' => self::FEATURE_LOOKBACK_OBSERVATIONS,
            'maximum_label_observations' => self::MAX_LABEL_OBSERVATIONS,
            'trading_observations_per_month' => self::TRADING_OBSERVATIONS_PER_MONTH,
            'observation_overhead_months' => (int) ceil(
                (self::FEATURE_LOOKBACK_OBSERVATIONS + self::MAX_LABEL_OBSERVATIONS)
                / self::TRADING_OBSERVATIONS_PER_MONTH,
            ),
            'required_calendar_months' => $this->requiredCalendarMonths(),
            'allocation_rationale' => '40 monthly buckets preserve approximately 28 train, 6 validation and 6 test buckets; observation overhead covers the 63-bar lookback and 126-bar forward label before leakage purging.',
        ];
    }
}
