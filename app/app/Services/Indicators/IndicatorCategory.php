<?php

namespace App\Services\Indicators;

/**
 * Indicator category IDs (SD-033 §5).
 * Extensible only via application release — not runtime plugins (SD-028).
 */
final class IndicatorCategory
{
    public const TREND = 'trend';

    public const MOMENTUM = 'momentum';

    public const VOLUME = 'volume';

    public const LIQUIDITY = 'liquidity';

    public const TRADABILITY = 'tradability';

    public const RISK = 'risk';

    public const VOLATILITY = 'volatility';

    public const RELATIVE_PERFORMANCE = 'relative_performance';

    public const MARKET = 'market';

    public const PRICE = 'price';

    public const DESCRIPTIVE = 'descriptive';

    /**
     * Human labels for Admin / docs.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TREND => 'Trend',
            self::MOMENTUM => 'Momentum',
            self::VOLUME => 'Volume',
            self::LIQUIDITY => 'Liquidity',
            self::TRADABILITY => 'Tradability',
            self::RISK => 'Risk',
            self::VOLATILITY => 'Volatility',
            self::RELATIVE_PERFORMANCE => 'Relative Performance',
            self::MARKET => 'Market',
            self::PRICE => 'Price',
            self::DESCRIPTIVE => 'Descriptive',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $category): bool
    {
        return isset(self::labels()[$category]);
    }

    /**
     * Map legacy SupportedIndicators display categories → Registry IDs.
     */
    public static function fromStrategyLabel(string $label): string
    {
        return match ($label) {
            'Momentum' => self::MOMENTUM,
            'Trend' => self::TREND,
            'Volume' => self::VOLUME,
            'Market' => self::MARKET,
            'Risk' => self::RISK,
            default => self::DESCRIPTIVE,
        };
    }
}
