<?php

namespace App\Services\Indicators;

/**
 * Declared consumers that may discover indicators via the Registry (SD-033 §8).
 * Discovery is metadata only in Epic 1 — production paths still use existing catalogues.
 */
final class IndicatorConsumer
{
    public const SCREENER = 'screener';

    public const STRATEGY = 'strategy';

    public const EVALUATION = 'evaluation';

    public const RECOMMENDATION = 'recommendation';

    public const DISCOVERY = 'discovery';

    public const DASHBOARD = 'dashboard';

    public const MARKET_ANALYTICS = 'market_analytics';

    public const PORTFOLIO_ANALYTICS = 'portfolio_analytics';

    public const STOCK_DETAILS = 'stock_details';

    public const ALERTS = 'alerts';

    public const ADMIN_REGISTRY = 'admin_registry';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SCREENER => 'Screener',
            self::STRATEGY => 'Strategy',
            self::EVALUATION => 'Evaluation',
            self::RECOMMENDATION => 'Recommendation',
            self::DISCOVERY => 'Discovery',
            self::DASHBOARD => 'Dashboard',
            self::MARKET_ANALYTICS => 'Market Analytics',
            self::PORTFOLIO_ANALYTICS => 'Portfolio Analytics',
            self::STOCK_DETAILS => 'Stock Details',
            self::ALERTS => 'Alerts',
            self::ADMIN_REGISTRY => 'Admin Registry',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $consumer): bool
    {
        return isset(self::labels()[$consumer]);
    }
}
