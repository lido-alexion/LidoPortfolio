<?php

namespace App\Engines\Strategy;

use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorRegistryFactory;
use App\Services\Indicators\StrategyCatalogueProjector;

/**
 * Strategy scoring catalogue façade (SD-028 / SD-033 Epic 2).
 *
 * Definitions project from {@see IndicatorRegistry}. Keys/aliases remain stable
 * for StrategyConfigurationService and Evaluation evidence.
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

    /** @var list<array<string, mixed>>|null */
    private static ?array $definitionsCache = null;

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
     * Backed by Indicator Registry (Epic 2).
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return self::$definitionsCache ??= StrategyCatalogueProjector::project(self::registry());
    }

    /**
     * Clear static projection cache (unit tests).
     */
    public static function clearDefinitionsCache(): void
    {
        self::$definitionsCache = null;
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

    private static function registry(): IndicatorRegistry
    {
        try {
            if (function_exists('app') && app()->bound(IndicatorRegistry::class)) {
                return app(IndicatorRegistry::class);
            }
        } catch (\Throwable) {
            // Unit tests without Laravel container.
        }

        return (new IndicatorRegistryFactory)->make();
    }
}
