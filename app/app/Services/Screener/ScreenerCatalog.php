<?php

namespace App\Services\Screener;

/**
 * Indicator catalog for API meta + param/min-bars validation.
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

    /**
     * @return list<array<string,mixed>>
     */
    public static function indicators(): array
    {
        return [
            self::ind('close', 'Close', [], 1),
            self::ind('open', 'Open', [], 1),
            self::ind('high', 'High', [], 1),
            self::ind('low', 'Low', [], 1),
            self::ind('volume', 'Volume', [], 1, needsVolume: true),
            self::ind('change_pct', '% Change', [
                self::param('period', 'Period', 1, 1, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 1) + 1),
            self::ind('high_n', 'Highest high (N)', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('low_n', 'Lowest low (N)', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('high_52w', '52-week high', [], 1),
            self::ind('low_52w', '52-week low', [], 1),
            self::ind('range_pct', 'Range % (H-L)/C', [], 1),
            self::ind('sma', 'SMA', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('ema', 'EMA', [
                self::param('period', 'Period', 50, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 50)),
            self::ind('price_vs_sma_pct', 'Price vs SMA %', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('price_vs_ema_pct', 'Price vs EMA %', [
                self::param('period', 'Period', 50, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 50)),
            self::ind('sma_spread_pct', 'SMA spread %', [
                self::param('fast', 'Fast', 20, self::PARAM_MIN_PERIOD, 400),
                self::param('slow', 'Slow', 50, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => max((int) ($p['fast'] ?? 20), (int) ($p['slow'] ?? 50))),
            self::ind('ema_spread_pct', 'EMA spread %', [
                self::param('fast', 'Fast', 12, self::PARAM_MIN_PERIOD, 400),
                self::param('slow', 'Slow', 26, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => max((int) ($p['fast'] ?? 12), (int) ($p['slow'] ?? 26))),
            self::ind('rsi', 'RSI', [
                self::param('period', 'Period', 14, self::PARAM_MIN_PERIOD, 200),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 14) + 1),
            self::ind('roc', 'ROC %', [
                self::param('period', 'Period', 12, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 12) + 1),
            self::ind('stoch_k', 'Stochastic %K', [
                self::param('period', 'Period', 14, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 14)),
            self::ind('stoch_d', 'Stochastic %D', [
                self::param('period', 'Period', 14, self::PARAM_MIN_PERIOD, 400),
                self::param('smooth', 'Smooth', 3, self::PARAM_MIN_PERIOD, 50),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 14) + (int) ($p['smooth'] ?? 3) - 1),
            self::ind('macd', 'MACD line', [
                self::param('fast', 'Fast', 12, self::PARAM_MIN_PERIOD, 400),
                self::param('slow', 'Slow', 26, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => max((int) ($p['fast'] ?? 12), (int) ($p['slow'] ?? 26))),
            self::ind('macd_signal', 'MACD signal', [
                self::param('fast', 'Fast', 12, self::PARAM_MIN_PERIOD, 400),
                self::param('slow', 'Slow', 26, self::PARAM_MIN_PERIOD, 400),
                self::param('signal', 'Signal', 9, self::PARAM_MIN_PERIOD, 100),
            ], null, minBarsFn: fn (array $p) => max((int) ($p['fast'] ?? 12), (int) ($p['slow'] ?? 26)) + (int) ($p['signal'] ?? 9) - 1),
            self::ind('macd_hist', 'MACD histogram', [
                self::param('fast', 'Fast', 12, self::PARAM_MIN_PERIOD, 400),
                self::param('slow', 'Slow', 26, self::PARAM_MIN_PERIOD, 400),
                self::param('signal', 'Signal', 9, self::PARAM_MIN_PERIOD, 100),
            ], null, minBarsFn: fn (array $p) => max((int) ($p['fast'] ?? 12), (int) ($p['slow'] ?? 26)) + (int) ($p['signal'] ?? 9) - 1),
            self::ind('atr', 'ATR', [
                self::param('period', 'Period', 14, self::PARAM_MIN_PERIOD, 200),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 14)),
            self::ind('bb_mid', 'Bollinger mid', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('bb_upper', 'Bollinger upper', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
                self::param('mult', 'Mult', 2, 0.5, 5, step: 0.1),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('bb_lower', 'Bollinger lower', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
                self::param('mult', 'Mult', 2, 0.5, 5, step: 0.1),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('bb_pct_b', 'Bollinger %B', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
                self::param('mult', 'Mult', 2, 0.5, 5, step: 0.1),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('bb_width_pct', 'Bollinger width %', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
                self::param('mult', 'Mult', 2, 0.5, 5, step: 0.1),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20)),
            self::ind('volume_sma', 'Volume SMA', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20), needsVolume: true),
            self::ind('volume_ratio', 'Volume / Vol SMA', [
                self::param('period', 'Period', 20, self::PARAM_MIN_PERIOD, 400),
            ], null, minBarsFn: fn (array $p) => (int) ($p['period'] ?? 20), needsVolume: true),
        ];
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
        foreach (self::indicators() as $ind) {
            if ($ind['id'] !== $id) {
                continue;
            }
            if (isset($ind['min_bars_fn']) && is_callable($ind['min_bars_fn'])) {
                return (int) ($ind['min_bars_fn'])($params);
            }

            return (int) ($ind['min_bars'] ?? 1);
        }

        return 1;
    }

    public static function needsVolume(string $id): bool
    {
        foreach (self::indicators() as $ind) {
            if ($ind['id'] === $id) {
                return (bool) ($ind['needs_volume'] ?? false);
            }
        }

        return false;
    }

    public static function indicatorIds(): array
    {
        return array_column(self::indicators(), 'id');
    }

    /**
     * @param  list<array<string,mixed>>  $params
     * @param  (callable(array):int)|null  $minBarsFn
     * @return array<string,mixed>
     */
    private static function ind(
        string $id,
        string $label,
        array $params,
        ?int $minBars,
        ?callable $minBarsFn = null,
        bool $needsVolume = false,
    ): array {
        $row = [
            'id' => $id,
            'label' => $label,
            'params' => $params,
            'min_bars' => $minBars,
            'needs_volume' => $needsVolume,
        ];
        if ($minBarsFn !== null) {
            $row['min_bars_fn'] = $minBarsFn;
            // Expose formula hint for UI
            $row['min_bars'] = $minBarsFn(array_column($params, 'default', 'id'));
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private static function param(
        string $id,
        string $label,
        float|int $default,
        float|int $min,
        float|int $max,
        float $step = 1,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'default' => $default,
            'min' => $min,
            'max' => $max,
            'step' => $step,
        ];
    }
}
