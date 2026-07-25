<?php

namespace App\Engines\Strategy;

/**
 * Fixed supported-indicator catalogue for momentum Strategy Configuration (SD-028).
 * Not a plugin framework — new indicators ship only via application releases.
 */
final class SupportedIndicators
{
    public const RELATIVE_STRENGTH = 'relative_strength';

    public const MOMENTUM_SCORE = 'momentum_score';

    public const TREND_SCORE = 'trend_score';

    public const BREAKOUT_SCORE = 'breakout_score';

    public const VOLUME_SCORE = 'volume_score';

    public const MARKET_REGIME = 'market_regime';

    public const SECTOR_STRENGTH = 'sector_strength';

    public const RISK_SCORE = 'risk_score';

    public const CATEGORY_MOMENTUM = 'Momentum';

    public const CATEGORY_TREND = 'Trend';

    public const CATEGORY_VOLUME = 'Volume';

    public const CATEGORY_MARKET = 'Market';

    public const CATEGORY_RISK = 'Risk';

    /**
     * Legacy Evaluation / Strategy keys → catalogue keys.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'momentum' => self::MOMENTUM_SCORE,
            'trend' => self::TREND_SCORE,
            'pattern_bonus' => self::BREAKOUT_SCORE,
            'volume' => self::VOLUME_SCORE,
            'risk' => self::RISK_SCORE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    public static function isSupported(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Canonical catalogue metadata (display + default params schema).
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => self::RELATIVE_STRENGTH,
                'category' => self::CATEGORY_MOMENTUM,
                'display_name' => 'Relative Strength',
                'description' => 'Relative strength vs benchmark over the lookback window.',
                'supports_maximum' => false,
                // Factory defaults (SD-029) — weights sum to 100 across enabled set.
                'default_enabled' => true,
                'default_weight' => 35,
                'default_minimum' => 80,
                'default_maximum' => null,
                'parameters' => [
                    'lookback_days' => ['type' => 'integer', 'label' => 'Lookback Period (days)', 'default' => 90],
                    'benchmark' => ['type' => 'string', 'label' => 'Benchmark', 'default' => 'NIFTY50'],
                ],
            ],
            [
                'key' => self::MOMENTUM_SCORE,
                'category' => self::CATEGORY_MOMENTUM,
                'display_name' => 'Momentum Score',
                'description' => 'RSI-based momentum strength (objective measurement).',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 15,
                'default_minimum' => 70,
                'default_maximum' => null,
                'parameters' => [
                    'rsi_period' => ['type' => 'integer', 'label' => 'RSI Period', 'default' => 14],
                ],
            ],
            [
                'key' => self::TREND_SCORE,
                'category' => self::CATEGORY_TREND,
                'display_name' => 'Trend Score',
                'description' => 'Price vs SMA stack trend strength.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 20,
                'default_minimum' => 70,
                'default_maximum' => null,
                'parameters' => [
                    'sma_fast' => ['type' => 'integer', 'label' => 'Fast SMA', 'default' => 20],
                    'sma_slow' => ['type' => 'integer', 'label' => 'Slow SMA', 'default' => 50],
                ],
            ],
            [
                'key' => self::BREAKOUT_SCORE,
                'category' => self::CATEGORY_TREND,
                'display_name' => 'Breakout Score',
                'description' => 'Pattern / breakout strength from discovery evidence.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 10,
                'default_minimum' => 75,
                'default_maximum' => null,
                'parameters' => [],
            ],
            [
                'key' => self::VOLUME_SCORE,
                'category' => self::CATEGORY_VOLUME,
                'display_name' => 'Volume Score',
                'description' => 'Volume expansion versus recent average.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 8,
                'default_minimum' => 60,
                'default_maximum' => null,
                'parameters' => [
                    'volume_sma_period' => ['type' => 'integer', 'label' => 'Volume SMA Period', 'default' => 20],
                ],
            ],
            [
                'key' => self::MARKET_REGIME,
                'category' => self::CATEGORY_MARKET,
                'display_name' => 'Market Regime',
                'description' => 'Broad market regime score (neutral stub until dedicated regime model ships).',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 5,
                'default_minimum' => 60,
                'default_maximum' => null,
                'parameters' => [],
            ],
            [
                'key' => self::SECTOR_STRENGTH,
                'category' => self::CATEGORY_MARKET,
                'display_name' => 'Sector Strength',
                'description' => 'Sector relative strength (neutral stub until sector model ships).',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 4,
                'default_minimum' => 60,
                'default_maximum' => null,
                'parameters' => [],
            ],
            [
                'key' => self::RISK_SCORE,
                'category' => self::CATEGORY_RISK,
                'display_name' => 'Risk Score',
                'description' => 'Volatility / ATR risk. Higher is riskier; use Maximum to cap acceptable risk.',
                'supports_maximum' => true,
                'default_enabled' => true,
                'default_weight' => 3,
                'default_minimum' => null,
                'default_maximum' => 40,
                'parameters' => [
                    'atr_period' => ['type' => 'integer', 'label' => 'ATR Period', 'default' => 14],
                ],
            ],
        ];
    }

    /**
     * Catalogue grouped by category for UI.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byCategory(): array
    {
        $grouped = [];
        foreach (self::definitions() as $def) {
            $grouped[$def['category']][] = $def;
        }

        return $grouped;
    }

    /**
     * Resolve a score map key to the catalogue key.
     */
    public static function canonicalizeKey(string $key): string
    {
        $aliases = self::aliases();

        return $aliases[$key] ?? $key;
    }

    /**
     * @param  array<string, mixed>  $scores
     * @return array<string, mixed>
     */
    public static function canonicalizeScoreMap(array $scores): array
    {
        $out = [];
        foreach ($scores as $key => $value) {
            $canon = self::canonicalizeKey((string) $key);
            if (! array_key_exists($canon, $out) || $out[$canon] === null) {
                $out[$canon] = $value;
            }
        }

        return $out;
    }
}
