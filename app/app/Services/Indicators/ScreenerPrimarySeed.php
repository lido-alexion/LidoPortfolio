<?php

namespace App\Services\Indicators;

use App\Services\Screener\ScreenerCatalog;

/**
 * Canonical seed rows for Screener / TechnicalIndicatorService Primaries.
 * Single source used by {@see IndicatorRegistryFactory}; ScreenerCatalog projects from Registry.
 *
 * min_bars formulas live in {@see ScreenerMinBars} (callables cannot live in Registry metadata).
 */
final class ScreenerPrimarySeed
{
    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   params: list<array<string, mixed>>,
     *   needs_volume?: bool,
     *   description?: string
     * }>
     */
    public static function rows(): array
    {
        $p = static fn (string $id, string $label, float|int $default, float|int $min, float|int $max, float $step = 1): array => [
            'id' => $id,
            'label' => $label,
            'default' => $default,
            'min' => $min,
            'max' => $max,
            'step' => $step,
        ];
        $minP = ScreenerCatalog::PARAM_MIN_PERIOD;

        return [
            ['id' => 'close', 'label' => 'Close', 'params' => []],
            ['id' => 'open', 'label' => 'Open', 'params' => []],
            ['id' => 'high', 'label' => 'High', 'params' => []],
            ['id' => 'low', 'label' => 'Low', 'params' => []],
            ['id' => 'volume', 'label' => 'Volume', 'params' => [], 'needs_volume' => true],
            ['id' => 'change_pct', 'label' => '% Change', 'params' => [$p('period', 'Period', 1, 1, 400)]],
            ['id' => 'high_n', 'label' => 'Highest high (N)', 'params' => [$p('period', 'Period', 20, $minP, 400)]],
            ['id' => 'low_n', 'label' => 'Lowest low (N)', 'params' => [$p('period', 'Period', 20, $minP, 400)]],
            ['id' => 'high_52w', 'label' => '52-week high', 'params' => []],
            ['id' => 'low_52w', 'label' => '52-week low', 'params' => []],
            ['id' => 'range_pct', 'label' => 'Range % (H-L)/C', 'params' => []],
            ['id' => 'sma', 'label' => 'SMA', 'params' => [$p('period', 'Period', 20, $minP, 400)]],
            ['id' => 'ema', 'label' => 'EMA', 'params' => [$p('period', 'Period', 50, $minP, 400)]],
            ['id' => 'price_vs_sma_pct', 'label' => 'Price vs SMA %', 'params' => [$p('period', 'Period', 20, $minP, 400)]],
            ['id' => 'price_vs_ema_pct', 'label' => 'Price vs EMA %', 'params' => [$p('period', 'Period', 50, $minP, 400)]],
            ['id' => 'sma_spread_pct', 'label' => 'SMA spread %', 'params' => [
                $p('fast', 'Fast', 20, $minP, 400),
                $p('slow', 'Slow', 50, $minP, 400),
            ]],
            ['id' => 'ema_spread_pct', 'label' => 'EMA spread %', 'params' => [
                $p('fast', 'Fast', 12, $minP, 400),
                $p('slow', 'Slow', 26, $minP, 400),
            ]],
            ['id' => 'rsi', 'label' => 'RSI', 'params' => [$p('period', 'Period', 14, $minP, 200)]],
            ['id' => 'roc', 'label' => 'ROC %', 'params' => [$p('period', 'Period', 12, $minP, 400)]],
            ['id' => 'stoch_k', 'label' => 'Stochastic %K', 'params' => [$p('period', 'Period', 14, $minP, 400)]],
            ['id' => 'stoch_d', 'label' => 'Stochastic %D', 'params' => [
                $p('period', 'Period', 14, $minP, 400),
                $p('smooth', 'Smooth', 3, $minP, 50),
            ]],
            ['id' => 'macd', 'label' => 'MACD line', 'params' => [
                $p('fast', 'Fast', 12, $minP, 400),
                $p('slow', 'Slow', 26, $minP, 400),
            ]],
            ['id' => 'macd_signal', 'label' => 'MACD signal', 'params' => [
                $p('fast', 'Fast', 12, $minP, 400),
                $p('slow', 'Slow', 26, $minP, 400),
                $p('signal', 'Signal', 9, $minP, 100),
            ]],
            ['id' => 'macd_hist', 'label' => 'MACD histogram', 'params' => [
                $p('fast', 'Fast', 12, $minP, 400),
                $p('slow', 'Slow', 26, $minP, 400),
                $p('signal', 'Signal', 9, $minP, 100),
            ]],
            ['id' => 'atr', 'label' => 'ATR', 'params' => [$p('period', 'Period', 14, $minP, 200)]],
            ['id' => 'bb_mid', 'label' => 'Bollinger mid', 'params' => [$p('period', 'Period', 20, $minP, 400)]],
            ['id' => 'bb_upper', 'label' => 'Bollinger upper', 'params' => [
                $p('period', 'Period', 20, $minP, 400),
                $p('mult', 'Mult', 2, 0.5, 5, 0.1),
            ]],
            ['id' => 'bb_lower', 'label' => 'Bollinger lower', 'params' => [
                $p('period', 'Period', 20, $minP, 400),
                $p('mult', 'Mult', 2, 0.5, 5, 0.1),
            ]],
            ['id' => 'bb_pct_b', 'label' => 'Bollinger %B', 'params' => [
                $p('period', 'Period', 20, $minP, 400),
                $p('mult', 'Mult', 2, 0.5, 5, 0.1),
            ]],
            ['id' => 'bb_width_pct', 'label' => 'Bollinger width %', 'params' => [
                $p('period', 'Period', 20, $minP, 400),
                $p('mult', 'Mult', 2, 0.5, 5, 0.1),
            ]],
            ['id' => 'volume_sma', 'label' => 'Volume SMA', 'params' => [$p('period', 'Period', 20, $minP, 400)], 'needs_volume' => true],
            ['id' => 'volume_ratio', 'label' => 'Volume / Vol SMA', 'params' => [$p('period', 'Period', 20, $minP, 400)], 'needs_volume' => true],
            [
                'id' => 'average_volume',
                'label' => 'Average Daily Volume',
                'params' => [$p('period', 'Period', 20, $minP, 400)],
                'needs_volume' => true,
                'description' => 'Mean share volume over the lookback period (Liquidity primary; same math as Volume SMA).',
                'formula_explanation' => 'ADV = mean(volume over last N sessions). Null volumes exclude the session from the window until N complete volume bars are available.',
            ],
            [
                'id' => 'average_turnover',
                'label' => 'Average Daily Turnover',
                'params' => [$p('period', 'Period', 20, $minP, 400)],
                'needs_volume' => true,
                'description' => 'Typical daily traded value: SMA of close × volume.',
                'formula_explanation' => 'Daily turnover_t = close_t × volume_t. Average Daily Turnover = SMA(turnover, N).',
            ],
            [
                'id' => 'relative_turnover',
                'label' => 'Relative Turnover',
                'params' => [
                    $p('period', 'Period', 20, $minP, 400),
                    $p('baseline', 'Baseline', 60, $minP, 400),
                ],
                'needs_volume' => true,
                'description' => 'Short-window average turnover divided by longer baseline average turnover (self-relative V1).',
                'formula_explanation' => 'relative_turnover = AverageDailyTurnover(period) / AverageDailyTurnover(baseline). Values near 1.0 mean turnover is in line with the stock’s own baseline; >1 means elevated liquidity vs baseline. Universe/benchmark relative mode is deferred.',
            ],
            [
                'id' => 'gap_frequency',
                'label' => 'Gap Frequency',
                'params' => [
                    $p('period', 'Period', 60, $minP, 400),
                    $p('threshold_pct', 'Gap threshold %', 1, 0.1, 20, 0.1),
                ],
                'description' => 'Rate of opening gaps vs prior close over the lookback.',
                'formula_explanation' => 'A gap occurs when open differs from prior close by more than threshold_pct. gap_frequency = gap_count / sessions_in_window.',
            ],
            [
                'id' => 'gap_fill_ratio',
                'label' => 'Gap Fill Ratio',
                'params' => [
                    $p('period', 'Period', 60, $minP, 400),
                    $p('threshold_pct', 'Gap threshold %', 1, 0.1, 20, 0.1),
                    $p('fill_window', 'Fill window', 5, 1, 40),
                ],
                'description' => 'Fraction of gaps that trade back through the prior close within the fill window.',
                'formula_explanation' => 'For each gap in the lookback, filled if within fill_window sessions the range crosses prior close (gap-up: low ≤ prior close; gap-down: high ≥ prior close). gap_fill_ratio = filled_gaps / gap_count (null when no gaps).',
            ],
            [
                'id' => 'circuit_frequency',
                'label' => 'Circuit Frequency',
                'params' => [
                    $p('period', 'Period', 60, $minP, 400),
                    $p('move_pct', 'Move %', 9.5, 1, 25, 0.1),
                    $p('range_pct', 'Max range %', 0.5, 0.05, 5, 0.05),
                ],
                'description' => 'Heuristic rate of circuit-like sessions from OHLCV (not exchange circuit feed).',
                'formula_explanation' => 'A session is flagged when |close−prior_close|/prior_close ≥ move_pct and (high−low)/close ≤ range_pct (locked-range large move). circuit_frequency = flagged / sessions. Exchange official circuits are not ingested in V1.',
            ],
            [
                'id' => 'circuit_risk',
                'label' => 'Circuit Risk',
                'params' => [
                    $p('period', 'Period', 60, $minP, 400),
                    $p('move_pct', 'Move %', 9.5, 1, 25, 0.1),
                    $p('range_pct', 'Max range %', 0.5, 0.05, 5, 0.05),
                ],
                'description' => '0–100 severity score from heuristic circuit frequency and move size.',
                'formula_explanation' => 'circuit_risk = min(100, frequency×70 + min(30, avg_abs_move_%_on_flagged_days)). Higher means more severe limit-like behaviour.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_column(self::rows(), 'id');
    }
}
