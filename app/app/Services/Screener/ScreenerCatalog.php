<?php

namespace App\Services\Screener;

use App\Services\Indicators\IndicatorCapability;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorRegistryFactory;
use App\Services\Indicators\ScreenerCatalogueProjector;
use App\Services\Indicators\ScreenerMinBars;

/**
 * Screener meta façade (SD-033 Epic 2).
 *
 * Indicator rows project from {@see IndicatorRegistry}. Operators, scopes, and
 * limits remain here. Calculation stays in {@see TechnicalIndicatorService}.
 */
class ScreenerCatalog
{
    public const SCOPES = ['holdings', 'watchlist', 'all_equities', 'index'];

    public const OPERATORS = [
        ['id' => 'gt', 'label' => '>'],
        ['id' => 'gte', 'label' => '≥'],
        ['id' => 'lt', 'label' => '<'],
        ['id' => 'lte', 'label' => '≤'],
        ['id' => 'eq', 'label' => '='],
    ];

    public static function operatorLabel(string $operatorId): string
    {
        foreach (self::OPERATORS as $op) {
            if ($op['id'] === $operatorId) {
                return $op['label'];
            }
        }

        return $operatorId !== '' ? $operatorId : '—';
    }

    /**
     * Entities the LEFT side of a condition can compute on.
     * 'stock' = the scanned stock; others are benchmark index symbols (is_benchmark Stock rows).
     */
    public const LEFT_ENTITIES = [
        ['id' => 'stock', 'label' => 'Stock'],
        ['id' => 'NIFTY50', 'label' => 'Nifty 50'],
        ['id' => 'SENSEX', 'label' => 'Sensex'],
        ['id' => 'NIFTY100', 'label' => 'Nifty 100'],
        ['id' => 'NIFTY200', 'label' => 'Nifty 200'],
        ['id' => 'NIFTY500', 'label' => 'Nifty 500'],
        ['id' => 'NIFTYMIDCAP150', 'label' => 'Nifty Midcap 150'],
        ['id' => 'NIFTYSMLCAP250', 'label' => 'Nifty Smallcap 250'],
    ];

    /**
     * @return list<string>
     */
    public static function entityIds(): array
    {
        return array_column(self::LEFT_ENTITIES, 'id');
    }

    public static function entityLabel(string $entityId): string
    {
        foreach (self::LEFT_ENTITIES as $ent) {
            if ($ent['id'] === $entityId) {
                return $ent['label'];
            }
        }

        return $entityId;
    }

    public const MAX_NESTING_DEPTH = 4;

    public const MAX_CONDITIONS = 40;

    public const CHUNK_SIZE = 150;

    public const TELEGRAM_HIT_CAP = 40;

    /** Runs returned in editor/history API (all runs remain in DB until cleared). */
    public const RUN_HISTORY_UI_LIMIT = 30;

    /** Stocks processed per backtest continue request (stock-major evaluation). */
    public const BACKTEST_STOCK_CHUNK = 150;

    /** Scopes allowed for as-of backtest (stock-major engine handles full universe). */
    public const BACKTEST_SCOPES = self::SCOPES;

    public const BACKTEST_RANGES = [
        ['id' => '1y', 'label' => '1 year'],
        ['id' => '6m', 'label' => '6 months'],
        ['id' => '3m', 'label' => '3 months'],
        ['id' => '1m', 'label' => '1 month'],
        ['id' => '15d', 'label' => '15 days'],
    ];

    /** Trading sessions in a 52-week lookback (NSE/BSE convention). */
    public const TRADING_DAYS_52W = 252;

    /** Smallest allowed period-style param (SMA/EMA period 1 = latest close). */
    public const PARAM_MIN_PERIOD = 1;

    /** @var list<array<string, mixed>>|null */
    private static ?array $indicatorRowsCache = null;

    /**
     * @return list<array<string,mixed>>
     */
    public static function indicators(): array
    {
        return self::$indicatorRowsCache ??= ScreenerCatalogueProjector::project(self::registry());
    }

    /**
     * Clear static projection cache (unit tests).
     */
    public static function clearIndicatorCache(): void
    {
        self::$indicatorRowsCache = null;
    }

    /**
     * @return array{indicators:list,operators:list,scopes:list,max_nesting:int,max_conditions:int,chunk_size:int}
     */
    public static function meta(): array
    {
        $indicators = array_map(function (array $ind) {
            unset($ind['min_bars_fn']);

            return $ind;
        }, self::indicators());

        return [
            'indicators' => $indicators,
            'operators' => self::OPERATORS,
            'scopes' => [
                ['id' => 'holdings', 'label' => 'Holdings'],
                ['id' => 'watchlist', 'label' => 'Watchlist'],
                ['id' => 'all_equities', 'label' => 'All equities'],
                ['id' => 'index', 'label' => 'Index constituents'],
            ],
            'left_entities' => self::LEFT_ENTITIES,
            'max_nesting' => self::MAX_NESTING_DEPTH,
            'max_conditions' => self::MAX_CONDITIONS,
            'chunk_size' => self::CHUNK_SIZE,
            'run_history_ui_limit' => self::RUN_HISTORY_UI_LIMIT,
            'param_min_period' => self::PARAM_MIN_PERIOD,
            'backtest_scopes' => self::BACKTEST_SCOPES,
            'backtest_ranges' => self::BACKTEST_RANGES,
            'backtest_stock_chunk' => self::BACKTEST_STOCK_CHUNK,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public static function minBars(string $id, array $params): int
    {
        return ScreenerMinBars::compute($id, $params);
    }

    public static function needsVolume(string $id): bool
    {
        $def = self::registry()->find($id);

        return $def?->hasCapability(IndicatorCapability::NEEDS_VOLUME) ?? false;
    }

    public static function indicatorIds(): array
    {
        return array_column(self::indicators(), 'id');
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
