<?php

namespace App\Services\Indicators;

/**
 * Formal indicator types (SD-033).
 *
 * Calculation ownership is unchanged:
 * - primary → TechnicalIndicatorService / RelativeStrengthService / dedicated services
 * - composite → EvaluationEngine (stock) / MarketAnalysisEngine (market-level)
 * - metric → Analytics services (descriptive; discoverable)
 */
final class IndicatorType
{
    public const PRIMARY = 'primary';

    public const COMPOSITE = 'composite';

    public const METRIC = 'metric';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::PRIMARY => 'Primary',
            self::COMPOSITE => 'Composite',
            self::METRIC => 'Metric',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $type): bool
    {
        return isset(self::labels()[$type]);
    }
}
