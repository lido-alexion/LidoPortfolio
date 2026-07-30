<?php

namespace App\Services\Indicators;

/**
 * Named capability flags stored on {@see IndicatorDefinition} (SD-033).
 */
final class IndicatorCapability
{
    public const NEEDS_VOLUME = 'needs_volume';

    public const SUPPORTS_MAXIMUM = 'supports_maximum';

    public const STRATEGY_SCORABLE = 'strategy_scorable';

    public const EVALUATION_FACT = 'evaluation_fact';

    /**
     * @return list<string>
     */
    public static function known(): array
    {
        return [
            self::NEEDS_VOLUME,
            self::SUPPORTS_MAXIMUM,
            self::STRATEGY_SCORABLE,
            self::EVALUATION_FACT,
        ];
    }
}
