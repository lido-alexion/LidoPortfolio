<?php

namespace App\Services\Screener;

use App\Services\Indicators\LiquidityTradabilityCalculator;

/**
 * Computes technical indicators from chronological OHLCV bar arrays.
 * Each bar: open, high, low, close, volume (floats|null). Series cache keyed per expression.
 */
class TechnicalIndicatorService
{
    public const EPSILON_ABS = 1e-4;

    public const EPSILON_REL = 1e-6;

    /** @var array<string, float|null> */
    private array $cache = [];

    /** @var array<string, list<?float>> */
    private array $seriesCache = [];

    /**
     * Shared sub-series memo (EMA/SMA per period, rolling std, %K, MACD line…)
     * so composed indicators never recompute a building block for the same bars.
     *
     * @var array<string, list<?float>>
     */
    private array $memo = [];

    private ?LiquidityTradabilityCalculator $ltCalculator = null;

    /** @var list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>|null */
    private ?array $validBarsCache = null;

    /**
     * @param  list<array{open:?float,high:?float,low:?float,close:?float,volume:?float}>  $bars
     */
    public function withBars(array $bars): self
    {
        $clone = clone $this;
        $clone->bars = $bars;
        $clone->cache = [];
        $clone->seriesCache = [];
        $clone->memo = [];
        $clone->validBarsCache = null;
        $clone->ltCalculator = null;

        return $clone;
    }

    /** @var list<array{open:?float,high:?float,low:?float,close:?float,volume:?float}> */
    private array $bars = [];

    public function clearCache(): void
    {
        $this->cache = [];
        $this->seriesCache = [];
        $this->memo = [];
        $this->validBarsCache = null;
        $this->ltCalculator = null;
    }

    /**
     * @param  callable():list<?float>  $fn
     * @return list<?float>
     */
    private function memoSeries(string $key, callable $fn): array
    {
        return $this->memo[$key] ??= $fn();
    }

    /**
     * SMA of closes, memoized per period.
     *
     * @return list<?float>
     */
    private function closeSma(int $period): array
    {
        return $this->memoSeries('sma|'.$period, fn () => $this->sma($this->series('close'), $period));
    }

    /**
     * EMA of closes, memoized per period.
     *
     * @return list<?float>
     */
    private function closeEma(int $period): array
    {
        return $this->memoSeries('ema|'.$period, fn () => $this->ema($this->series('close'), $period));
    }

    /**
     * @param  array{indicator:string,params?:array<string,mixed>}|array{type:string,value:mixed}  $expr
     */
    public function evaluate(array $expr): ?float
    {
        if (($expr['type'] ?? null) === 'constant') {
            return is_numeric($expr['value'] ?? null) ? (float) $expr['value'] : null;
        }

        $id = (string) ($expr['indicator'] ?? '');
        $params = is_array($expr['params'] ?? null) ? $expr['params'] : [];
        $key = $id.'|'.json_encode($params, JSON_THROW_ON_ERROR);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->compute($id, $params);
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Full per-bar series for an expression, aligned to validBars() indexes.
     * Every series is causal — the value at index i only uses bars 0..i — so
     * series[i] matches what evaluate() would return on the prefix ending at i
     * (window indicators exactly; EMA-seeded ones given the same history start).
     * Used by the stock-major backtest to evaluate many as-of dates in one pass.
     *
     * @param  array{indicator?:string,params?:array<string,mixed>,type?:string,value?:mixed}  $expr
     * @return list<?float>
     */
    public function evaluateSeries(array $expr): array
    {
        $n = count($this->validBars());
        if (($expr['type'] ?? null) === 'constant') {
            $v = is_numeric($expr['value'] ?? null) ? (float) $expr['value'] : null;

            return $n > 0 ? array_fill(0, $n, $v) : [];
        }

        $id = (string) ($expr['indicator'] ?? '');
        $params = is_array($expr['params'] ?? null) ? $expr['params'] : [];
        $key = $id.'|'.json_encode($params, JSON_THROW_ON_ERROR);

        if (array_key_exists($key, $this->seriesCache)) {
            return $this->seriesCache[$key];
        }

        $series = $this->computeSeries($id, $params);
        $this->seriesCache[$key] = $series;

        return $series;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return list<?float>
     */
    private function computeSeries(string $id, array $params): array
    {
        $closes = $this->series('close');

        return match ($id) {
            'close' => $closes,
            'open' => $this->fieldSeries('open'),
            'high' => $this->fieldSeries('high'),
            'low' => $this->fieldSeries('low'),
            'volume' => $this->fieldSeries('volume'),
            'change_pct' => $this->changePctSeries((int) ($params['period'] ?? 1)),
            'roc' => $this->changePctSeries((int) ($params['period'] ?? 12)),
            'high_n' => $this->rollingExtremeSeries('high', (int) ($params['period'] ?? 20), true, false),
            'low_n' => $this->rollingExtremeSeries('low', (int) ($params['period'] ?? 20), false, false),
            'high_52w' => $this->rollingExtremeSeries('high', ScreenerCatalog::TRADING_DAYS_52W, true, true),
            'low_52w' => $this->rollingExtremeSeries('low', ScreenerCatalog::TRADING_DAYS_52W, false, true),
            'range_pct' => $this->rangePctSeries(),
            'sma' => $this->closeSma((int) ($params['period'] ?? 20)),
            'ema' => $this->closeEma((int) ($params['period'] ?? 20)),
            'price_vs_sma_pct' => $this->priceVsMaPctSeries('sma', (int) ($params['period'] ?? 20)),
            'price_vs_ema_pct' => $this->priceVsMaPctSeries('ema', (int) ($params['period'] ?? 20)),
            'sma_spread_pct' => $this->maSpreadPctSeries('sma', (int) ($params['fast'] ?? 20), (int) ($params['slow'] ?? 50)),
            'ema_spread_pct' => $this->maSpreadPctSeries('ema', (int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26)),
            'rsi' => $this->closeRsi((int) ($params['period'] ?? 14)),
            'stoch_k' => $this->stochK((int) ($params['period'] ?? 14)),
            'stoch_d' => $this->stochD((int) ($params['period'] ?? 14), (int) ($params['smooth'] ?? 3)),
            'macd' => $this->macdLine((int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26)),
            'macd_signal' => $this->macdSignal(
                (int) ($params['fast'] ?? 12),
                (int) ($params['slow'] ?? 26),
                (int) ($params['signal'] ?? 9),
            ),
            'macd_hist' => $this->macdHist(
                (int) ($params['fast'] ?? 12),
                (int) ($params['slow'] ?? 26),
                (int) ($params['signal'] ?? 9),
            ),
            'atr' => $this->atr((int) ($params['period'] ?? 14)),
            'bb_mid' => $this->closeSma((int) ($params['period'] ?? 20)),
            'bb_upper' => $this->bbBandSeries('upper', (int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_lower' => $this->bbBandSeries('lower', (int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_pct_b' => $this->bbPctBSeries((int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_width_pct' => $this->bbWidthPctSeries((int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'volume_sma' => $this->volumeSmaSeries((int) ($params['period'] ?? 20)),
            'volume_ratio' => $this->volumeRatioSeries((int) ($params['period'] ?? 20)),
            'average_volume' => $this->volumeSmaSeries((int) ($params['period'] ?? 20)),
            'average_turnover' => $this->ltCalculator()->averageTurnoverSeries(
                $this->validBars(),
                (int) ($params['period'] ?? 20),
            ),
            'relative_turnover' => $this->ltCalculator()->relativeTurnoverSeries(
                $this->validBars(),
                (int) ($params['period'] ?? 20),
                (int) ($params['baseline'] ?? 60),
            ),
            'gap_frequency' => $this->ltCalculator()->gapFrequencySeries(
                $this->validBars(),
                (int) ($params['period'] ?? 60),
                (float) ($params['threshold_pct'] ?? 1),
            ),
            'gap_fill_ratio' => $this->ltCalculator()->gapFillRatioSeries(
                $this->validBars(),
                (int) ($params['period'] ?? 60),
                (float) ($params['threshold_pct'] ?? 1),
                (int) ($params['fill_window'] ?? 5),
            ),
            'circuit_frequency' => $this->ltCalculator()->circuitFrequencySeries(
                $this->validBars(),
                (int) ($params['period'] ?? 60),
                (float) ($params['move_pct'] ?? 9.5),
                (float) ($params['range_pct'] ?? 0.5),
            ),
            'circuit_risk' => $this->ltCalculator()->circuitRiskSeries(
                $this->validBars(),
                (int) ($params['period'] ?? 60),
                (float) ($params['move_pct'] ?? 9.5),
                (float) ($params['range_pct'] ?? 0.5),
            ),
            default => array_fill(0, max(0, count($closes)), null),
        };
    }

    /**
     * Minimum bars required for an indicator expression (excluding constant).
     *
     * @param  array{indicator?:string,params?:array<string,mixed>,type?:string}  $expr
     */
    public function minBarsFor(array $expr): int
    {
        if (($expr['type'] ?? null) === 'constant') {
            return 0;
        }

        $id = (string) ($expr['indicator'] ?? '');
        $params = is_array($expr['params'] ?? null) ? $expr['params'] : [];

        return ScreenerCatalog::minBars($id, $params);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function compute(string $id, array $params): ?float
    {
        $closes = $this->series('close');
        $n = count($closes);

        return match ($id) {
            'close' => $this->lastField('close'),
            'open' => $this->lastField('open'),
            'high' => $this->lastField('high'),
            'low' => $this->lastField('low'),
            'volume' => $this->lastField('volume'),
            'change_pct' => $this->changePct((int) ($params['period'] ?? 1)),
            'high_n' => $this->highN((int) ($params['period'] ?? 20)),
            'low_n' => $this->lowN((int) ($params['period'] ?? 20)),
            'high_52w' => $this->high52w(),
            'low_52w' => $this->low52w(),
            'range_pct' => $this->rangePct(),
            'sma' => $this->lastOf($this->closeSma((int) ($params['period'] ?? 20))),
            'ema' => $this->lastOf($this->closeEma((int) ($params['period'] ?? 20))),
            'price_vs_sma_pct' => $this->priceVsMaPct('sma', (int) ($params['period'] ?? 20)),
            'price_vs_ema_pct' => $this->priceVsMaPct('ema', (int) ($params['period'] ?? 20)),
            'sma_spread_pct' => $this->maSpreadPct('sma', (int) ($params['fast'] ?? 20), (int) ($params['slow'] ?? 50)),
            'ema_spread_pct' => $this->maSpreadPct('ema', (int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26)),
            'rsi' => $this->lastOf($this->closeRsi((int) ($params['period'] ?? 14))),
            'roc' => $this->roc((int) ($params['period'] ?? 12)),
            'stoch_k' => $this->lastOf($this->stochK((int) ($params['period'] ?? 14))),
            'stoch_d' => $this->lastOf($this->stochD((int) ($params['period'] ?? 14), (int) ($params['smooth'] ?? 3))),
            'macd' => $this->lastOf($this->macdLine((int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26))),
            'macd_signal' => $this->lastOf($this->macdSignal(
                (int) ($params['fast'] ?? 12),
                (int) ($params['slow'] ?? 26),
                (int) ($params['signal'] ?? 9),
            )),
            'macd_hist' => $this->lastOf($this->macdHist(
                (int) ($params['fast'] ?? 12),
                (int) ($params['slow'] ?? 26),
                (int) ($params['signal'] ?? 9),
            )),
            'atr' => $this->lastOf($this->atr((int) ($params['period'] ?? 14))),
            'bb_mid' => $this->lastOf($this->closeSma((int) ($params['period'] ?? 20))),
            'bb_upper' => $this->bbBand('upper', (int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_lower' => $this->bbBand('lower', (int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_pct_b' => $this->bbPctB((int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_width_pct' => $this->bbWidthPct((int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'volume_sma' => $this->lastOf($this->sma($this->series('volume'), (int) ($params['period'] ?? 20))),
            'volume_ratio' => $this->volumeRatio((int) ($params['period'] ?? 20)),
            'average_volume',
            'average_turnover',
            'relative_turnover',
            'gap_frequency',
            'gap_fill_ratio',
            'circuit_frequency',
            'circuit_risk' => $this->lastOf($this->computeSeries($id, $params)),
            default => null,
        };
    }

    private function ltCalculator(): LiquidityTradabilityCalculator
    {
        return $this->ltCalculator ??= new LiquidityTradabilityCalculator;
    }

    /**
     * @return list<float>
     */
    private function series(string $field): array
    {
        return $this->memo['series|'.$field] ??= $this->buildSeries($field);
    }

    /**
     * @return list<float>
     */
    private function buildSeries(string $field): array
    {
        $out = [];
        foreach ($this->bars as $bar) {
            $close = $bar['close'] ?? null;
            if ($close === null && isset($bar['adjusted_close'])) {
                $close = $bar['adjusted_close'];
            }
            if ($close === null) {
                continue;
            }
            if ($field === 'close') {
                $out[] = (float) $close;

                continue;
            }
            $v = $bar[$field] ?? null;
            if ($v === null) {
                continue;
            }
            $out[] = (float) $v;
        }

        return $out;
    }

    /**
     * Bars with null close dropped; used for OHLC-aligned indicators.
     *
     * @return list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>
     */
    private function validBars(): array
    {
        if ($this->validBarsCache !== null) {
            return $this->validBarsCache;
        }

        $out = [];
        foreach ($this->bars as $bar) {
            $close = $bar['close'] ?? null;
            if ($close === null && isset($bar['adjusted_close'])) {
                $close = $bar['adjusted_close'];
            }
            if ($close === null) {
                continue;
            }
            $out[] = [
                'open' => isset($bar['open']) ? (float) $bar['open'] : null,
                'high' => isset($bar['high']) ? (float) $bar['high'] : null,
                'low' => isset($bar['low']) ? (float) $bar['low'] : null,
                'close' => (float) $close,
                'volume' => isset($bar['volume']) ? (float) $bar['volume'] : null,
            ];
        }

        return $this->validBarsCache = $out;
    }

    /**
     * Field values aligned to validBars() indexes, keeping nulls in place.
     *
     * @return list<?float>
     */
    private function fieldSeries(string $field): array
    {
        return $this->memoSeries('field|'.$field, function () use ($field): array {
            $out = [];
            foreach ($this->validBars() as $bar) {
                $v = $bar[$field] ?? null;
                $out[] = $v === null ? null : (float) $v;
            }

            return $out;
        });
    }

    /**
     * @return list<?float>
     */
    private function changePctSeries(int $period): array
    {
        $closes = $this->series('close');
        $period = max(1, $period);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = $period; $i < $n; $i++) {
            $prev = $closes[$i - $period];
            if (abs($prev) < self::EPSILON_ABS) {
                continue;
            }
            $out[$i] = (($closes[$i] - $prev) / $prev) * 100.0;
        }

        return $out;
    }

    /**
     * Rolling max/min via monotonic deque (O(n)). When $allowPartial the window
     * grows from the first bar (52-week style); otherwise values before a full
     * window stay null (high_n/low_n semantics).
     *
     * @return list<?float>
     */
    private function rollingExtremeSeries(string $field, int $window, bool $isMax, bool $allowPartial): array
    {
        $window = max(1, $window);
        $key = 'extreme|'.$field.'|'.$window.'|'.($isMax ? 'max' : 'min').'|'.($allowPartial ? 'p' : 'f');

        return $this->memoSeries($key, fn () => $this->buildRollingExtremeSeries($field, $window, $isMax, $allowPartial));
    }

    /**
     * @return list<?float>
     */
    private function buildRollingExtremeSeries(string $field, int $window, bool $isMax, bool $allowPartial): array
    {
        $bars = $this->validBars();
        $n = count($bars);
        $vals = [];
        foreach ($bars as $bar) {
            $vals[] = (float) ($bar[$field] ?? $bar['close']);
        }
        $out = array_fill(0, $n, null);
        $deque = [];
        $head = 0;
        for ($i = 0; $i < $n; $i++) {
            while (count($deque) > $head) {
                $tail = $vals[$deque[count($deque) - 1]];
                if (($isMax && $tail <= $vals[$i]) || (! $isMax && $tail >= $vals[$i])) {
                    array_pop($deque);
                } else {
                    break;
                }
            }
            $deque[] = $i;
            while ($deque[$head] <= $i - $window) {
                $head++;
            }
            if ($allowPartial || $i >= $window - 1) {
                $out[$i] = $vals[$deque[$head]];
            }
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    private function rangePctSeries(): array
    {
        $out = [];
        foreach ($this->validBars() as $bar) {
            $high = $bar['high'];
            $low = $bar['low'];
            $close = $bar['close'];
            $out[] = ($high === null || $low === null || abs($close) < self::EPSILON_ABS)
                ? null
                : (($high - $low) / $close) * 100.0;
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    private function priceVsMaPctSeries(string $kind, int $period): array
    {
        $closes = $this->series('close');
        $ma = $kind === 'ema' ? $this->closeEma($period) : $this->closeSma($period);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            $m = $ma[$i];
            if ($m === null || abs($m) < self::EPSILON_ABS) {
                continue;
            }
            $out[$i] = (($closes[$i] - $m) / $m) * 100.0;
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    private function maSpreadPctSeries(string $kind, int $fast, int $slow): array
    {
        $closes = $this->series('close');
        $fastSeries = $kind === 'ema' ? $this->closeEma($fast) : $this->closeSma($fast);
        $slowSeries = $kind === 'ema' ? $this->closeEma($slow) : $this->closeSma($slow);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            $f = $fastSeries[$i];
            $s = $slowSeries[$i];
            if ($f === null || $s === null || abs($s) < self::EPSILON_ABS) {
                continue;
            }
            $out[$i] = (($f - $s) / $s) * 100.0;
        }

        return $out;
    }

    /**
     * Rolling standard deviation via running sums (O(n)).
     *
     * @param  list<float>  $values
     * @return list<?float>
     */
    private function rollingStdSeries(array $values, int $window): array
    {
        $window = max(1, $window);
        $n = count($values);
        // Only close-based callers exist; memoize per window.
        return $this->memoSeries('std|'.$window, fn () => $this->buildRollingStdSeries($values, $window, $n));
    }

    /**
     * @param  list<float>  $values
     * @return list<?float>
     */
    private function buildRollingStdSeries(array $values, int $window, int $n): array
    {
        $out = array_fill(0, $n, null);
        $sum = 0.0;
        $sumSq = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            $sumSq += $values[$i] ** 2;
            if ($i >= $window) {
                $old = $values[$i - $window];
                $sum -= $old;
                $sumSq -= $old ** 2;
            }
            if ($i >= $window - 1) {
                $mean = $sum / $window;
                $out[$i] = sqrt(max(0.0, ($sumSq / $window) - ($mean * $mean)));
            }
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    private function bbBandSeries(string $which, int $period, float $mult): array
    {
        return $this->memoSeries('bb|'.$which.'|'.$period.'|'.$mult, function () use ($which, $period, $mult): array {
            $closes = $this->series('close');
            $mid = $this->closeSma($period);
            $std = $this->rollingStdSeries($closes, $period);
            $n = count($closes);
            $out = array_fill(0, $n, null);
            for ($i = 0; $i < $n; $i++) {
                if ($mid[$i] === null || $std[$i] === null) {
                    continue;
                }
                $out[$i] = $which === 'upper'
                    ? $mid[$i] + ($mult * $std[$i])
                    : $mid[$i] - ($mult * $std[$i]);
            }

            return $out;
        });
    }

    /**
     * @return list<?float>
     */
    private function bbPctBSeries(int $period, float $mult): array
    {
        $closes = $this->series('close');
        $upper = $this->bbBandSeries('upper', $period, $mult);
        $lower = $this->bbBandSeries('lower', $period, $mult);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($upper[$i] === null || $lower[$i] === null) {
                continue;
            }
            $range = $upper[$i] - $lower[$i];
            if (abs($range) < self::EPSILON_ABS) {
                continue;
            }
            $out[$i] = ($closes[$i] - $lower[$i]) / $range;
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    private function bbWidthPctSeries(int $period, float $mult): array
    {
        $closes = $this->series('close');
        $upper = $this->bbBandSeries('upper', $period, $mult);
        $lower = $this->bbBandSeries('lower', $period, $mult);
        $mid = $this->closeSma($period);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($upper[$i] === null || $lower[$i] === null || $mid[$i] === null || abs($mid[$i]) < self::EPSILON_ABS) {
                continue;
            }
            $out[$i] = (($upper[$i] - $lower[$i]) / $mid[$i]) * 100.0;
        }

        return $out;
    }

    /**
     * SMA over a nullable series: any null inside the window yields null.
     *
     * @param  list<?float>  $values
     * @return list<?float>
     */
    private function smaNullableSeries(array $values, int $window): array
    {
        $window = max(1, $window);
        $n = count($values);
        $out = array_fill(0, $n, null);
        $sum = 0.0;
        $nulls = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($values[$i] === null) {
                $nulls++;
            } else {
                $sum += $values[$i];
            }
            if ($i >= $window) {
                $old = $values[$i - $window];
                if ($old === null) {
                    $nulls--;
                } else {
                    $sum -= $old;
                }
            }
            if ($i >= $window - 1 && $nulls === 0) {
                $out[$i] = $sum / $window;
            }
        }

        return $out;
    }

    /**
     * RSI of closes, memoized per period.
     *
     * @return list<?float>
     */
    private function closeRsi(int $period): array
    {
        return $this->memoSeries('rsi|'.max(1, $period), fn () => $this->rsi($this->series('close'), $period));
    }

    /**
     * Null-aware volume SMA, memoized per period.
     *
     * @return list<?float>
     */
    private function volumeSmaSeries(int $period): array
    {
        return $this->memoSeries(
            'vsma|'.max(1, $period),
            fn () => $this->smaNullableSeries($this->fieldSeries('volume'), $period),
        );
    }

    /**
     * @return list<?float>
     */
    private function volumeRatioSeries(int $period): array
    {
        $volumes = $this->fieldSeries('volume');
        $volSma = $this->volumeSmaSeries($period);
        $n = count($volumes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($volumes[$i] === null || $volSma[$i] === null || abs($volSma[$i]) < self::EPSILON_ABS) {
                continue;
            }
            $out[$i] = $volumes[$i] / $volSma[$i];
        }

        return $out;
    }

    private function lastField(string $field): ?float
    {
        $bars = $this->validBars();
        if ($bars === []) {
            return null;
        }
        $last = $bars[array_key_last($bars)];
        $v = $last[$field] ?? null;

        return $v === null ? null : (float) $v;
    }

    private function changePct(int $period): ?float
    {
        $closes = $this->series('close');
        $period = max(1, $period);
        if (count($closes) < $period + 1) {
            return null;
        }
        $cur = $closes[array_key_last($closes)];
        $prev = $closes[count($closes) - 1 - $period];
        if (abs($prev) < self::EPSILON_ABS) {
            return null;
        }

        return (($cur - $prev) / $prev) * 100.0;
    }

    private function highN(int $period): ?float
    {
        $bars = $this->validBars();
        $period = max(1, $period);
        if (count($bars) < $period) {
            return null;
        }
        $slice = array_slice($bars, -$period);
        $highs = array_map(fn ($b) => $b['high'] ?? $b['close'], $slice);

        return max($highs);
    }

    private function lowN(int $period): ?float
    {
        $bars = $this->validBars();
        $period = max(1, $period);
        if (count($bars) < $period) {
            return null;
        }
        $slice = array_slice($bars, -$period);
        $lows = array_map(fn ($b) => $b['low'] ?? $b['close'], $slice);

        return min($lows);
    }

    /**
     * Rolling high over up to 252 sessions; uses all available bars when history is shorter.
     */
    private function high52w(): ?float
    {
        $bars = $this->validBars();
        if ($bars === []) {
            return null;
        }

        return $this->highN(min(count($bars), ScreenerCatalog::TRADING_DAYS_52W));
    }

    /**
     * Rolling low over up to 252 sessions; uses all available bars when history is shorter.
     */
    private function low52w(): ?float
    {
        $bars = $this->validBars();
        if ($bars === []) {
            return null;
        }

        return $this->lowN(min(count($bars), ScreenerCatalog::TRADING_DAYS_52W));
    }

    private function rangePct(): ?float
    {
        $bars = $this->validBars();
        if ($bars === []) {
            return null;
        }
        $last = $bars[array_key_last($bars)];
        $high = $last['high'] ?? null;
        $low = $last['low'] ?? null;
        $close = $last['close'];
        if ($high === null || $low === null || abs($close) < self::EPSILON_ABS) {
            return null;
        }

        return (($high - $low) / $close) * 100.0;
    }

    /**
     * @param  list<float>  $values
     * @return list<?float>
     */
    public function sma(array $values, int $period): array
    {
        $period = max(1, $period);
        $out = array_fill(0, count($values), null);
        if (count($values) < $period) {
            return $out;
        }
        $sum = array_sum(array_slice($values, 0, $period));
        $out[$period - 1] = $sum / $period;
        for ($i = $period; $i < count($values); $i++) {
            $sum += $values[$i] - $values[$i - $period];
            $out[$i] = $sum / $period;
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     * @return list<?float>
     */
    public function ema(array $values, int $period): array
    {
        $period = max(1, $period);
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($n < $period) {
            return $out;
        }
        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $out[$period - 1] = $seed;
        $k = 2.0 / ($period + 1);
        $prev = $seed;
        for ($i = $period; $i < $n; $i++) {
            $prev = ($values[$i] - $prev) * $k + $prev;
            $out[$i] = $prev;
        }

        return $out;
    }

    private function priceVsMaPct(string $kind, int $period): ?float
    {
        $closes = $this->series('close');
        $ma = $kind === 'ema' ? $this->closeEma($period) : $this->closeSma($period);
        $lastMa = $this->lastOf($ma);
        $close = $this->lastOf($closes);
        if ($lastMa === null || $close === null || abs($lastMa) < self::EPSILON_ABS) {
            return null;
        }

        return (($close - $lastMa) / $lastMa) * 100.0;
    }

    private function maSpreadPct(string $kind, int $fast, int $slow): ?float
    {
        $closes = $this->series('close');
        $fastSeries = $kind === 'ema' ? $this->closeEma($fast) : $this->closeSma($fast);
        $slowSeries = $kind === 'ema' ? $this->closeEma($slow) : $this->closeSma($slow);
        $f = $this->lastOf($fastSeries);
        $s = $this->lastOf($slowSeries);
        if ($f === null || $s === null || abs($s) < self::EPSILON_ABS) {
            return null;
        }

        return (($f - $s) / $s) * 100.0;
    }

    /**
     * @param  list<float>  $closes
     * @return list<?float>
     */
    public function rsi(array $closes, int $period): array
    {
        $period = max(1, $period);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n < $period + 1) {
            return $out;
        }
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff >= 0) {
                $gains += $diff;
            } else {
                $losses -= $diff;
            }
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $out[$period] = $this->rsiFromAvg($avgGain, $avgLoss);
        for ($i = $period + 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = $diff > 0 ? $diff : 0.0;
            $loss = $diff < 0 ? -$diff : 0.0;
            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
            $out[$i] = $this->rsiFromAvg($avgGain, $avgLoss);
        }

        return $out;
    }

    private function rsiFromAvg(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss < self::EPSILON_ABS) {
            return 100.0;
        }
        $rs = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $rs));
    }

    private function roc(int $period): ?float
    {
        return $this->changePct($period);
    }

    /**
     * O(n) via monotonic-deque rolling highs/lows (no per-bar window re-scan).
     *
     * @return list<?float>
     */
    public function stochK(int $period): array
    {
        $period = max(1, $period);

        return $this->memoSeries('stochK|'.$period, function () use ($period): array {
            $bars = $this->validBars();
            $n = count($bars);
            $highs = $this->rollingExtremeSeries('high', $period, true, false);
            $lows = $this->rollingExtremeSeries('low', $period, false, false);
            $out = array_fill(0, $n, null);
            for ($i = $period - 1; $i < $n; $i++) {
                $high = $highs[$i];
                $low = $lows[$i];
                if ($high === null || $low === null) {
                    continue;
                }
                $range = $high - $low;
                if (abs($range) < self::EPSILON_ABS) {
                    continue;
                }
                $out[$i] = (($bars[$i]['close'] - $low) / $range) * 100.0;
            }

            return $out;
        });
    }

    /**
     * SMA of %K over the smoothing window (any null %K in the window → null).
     *
     * @return list<?float>
     */
    public function stochD(int $period, int $smooth): array
    {
        $smooth = max(1, $smooth);

        return $this->memoSeries(
            'stochD|'.max(1, $period).'|'.$smooth,
            fn () => $this->smaNullableSeries($this->stochK($period), $smooth),
        );
    }

    /**
     * @return list<?float>
     */
    public function macdLine(int $fast, int $slow): array
    {
        return $this->memoSeries('macd|'.$fast.'|'.$slow, function () use ($fast, $slow): array {
            $fastEma = $this->closeEma($fast);
            $slowEma = $this->closeEma($slow);
            $n = count($fastEma);
            $out = array_fill(0, $n, null);
            for ($i = 0; $i < $n; $i++) {
                if ($fastEma[$i] === null || $slowEma[$i] === null) {
                    continue;
                }
                $out[$i] = $fastEma[$i] - $slowEma[$i];
            }

            return $out;
        });
    }

    /**
     * @return list<?float>
     */
    public function macdSignal(int $fast, int $slow, int $signal): array
    {
        return $this->memoSeries('macdSignal|'.$fast.'|'.$slow.'|'.$signal, function () use ($fast, $slow, $signal): array {
            $macd = $this->macdLine($fast, $slow);
            $values = [];
            $indexMap = [];
            foreach ($macd as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $values[] = $v;
                $indexMap[] = $i;
            }
            $ema = $this->ema($values, $signal);
            $out = array_fill(0, count($macd), null);
            foreach ($ema as $j => $v) {
                if ($v === null) {
                    continue;
                }
                $out[$indexMap[$j]] = $v;
            }

            return $out;
        });
    }

    /**
     * @return list<?float>
     */
    public function macdHist(int $fast, int $slow, int $signal): array
    {
        $macd = $this->macdLine($fast, $slow);
        $sig = $this->macdSignal($fast, $slow, $signal);
        $n = count($macd);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] === null || $sig[$i] === null) {
                continue;
            }
            $out[$i] = $macd[$i] - $sig[$i];
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    public function atr(int $period): array
    {
        return $this->memoSeries('atr|'.max(1, $period), fn () => $this->atrSeries($period));
    }

    /**
     * @return list<?float>
     */
    private function atrSeries(int $period): array
    {
        $bars = $this->validBars();
        $period = max(1, $period);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        if ($n < $period) {
            return $out;
        }
        $trs = [];
        for ($i = 0; $i < $n; $i++) {
            $high = $bars[$i]['high'] ?? $bars[$i]['close'];
            $low = $bars[$i]['low'] ?? $bars[$i]['close'];
            if ($i === 0) {
                $trs[] = $high - $low;
            } else {
                $prevClose = $bars[$i - 1]['close'];
                $trs[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
            }
        }
        $sum = array_sum(array_slice($trs, 0, $period));
        $out[$period - 1] = $sum / $period;
        $prev = $out[$period - 1];
        for ($i = $period; $i < $n; $i++) {
            $prev = (($prev * ($period - 1)) + $trs[$i]) / $period;
            $out[$i] = $prev;
        }

        return $out;
    }

    private function bbBand(string $which, int $period, float $mult): ?float
    {
        $closes = $this->series('close');
        $midSeries = $this->closeSma($period);
        $mid = $this->lastOf($midSeries);
        if ($mid === null || count($closes) < $period) {
            return null;
        }
        $slice = array_slice($closes, -$period);
        $mean = array_sum($slice) / $period;
        $var = 0.0;
        foreach ($slice as $v) {
            $var += ($v - $mean) ** 2;
        }
        $std = sqrt($var / $period);
        if ($which === 'upper') {
            return $mid + ($mult * $std);
        }

        return $mid - ($mult * $std);
    }

    private function bbPctB(int $period, float $mult): ?float
    {
        $upper = $this->bbBand('upper', $period, $mult);
        $lower = $this->bbBand('lower', $period, $mult);
        $close = $this->lastField('close');
        if ($upper === null || $lower === null || $close === null) {
            return null;
        }
        $range = $upper - $lower;
        if (abs($range) < self::EPSILON_ABS) {
            return null;
        }

        return ($close - $lower) / $range;
    }

    private function bbWidthPct(int $period, float $mult): ?float
    {
        $upper = $this->bbBand('upper', $period, $mult);
        $lower = $this->bbBand('lower', $period, $mult);
        $mid = $this->lastOf($this->sma($this->series('close'), $period));
        if ($upper === null || $lower === null || $mid === null || abs($mid) < self::EPSILON_ABS) {
            return null;
        }

        return (($upper - $lower) / $mid) * 100.0;
    }

    private function volumeRatio(int $period): ?float
    {
        $volumes = $this->series('volume');
        $sma = $this->lastOf($this->sma($volumes, $period));
        $vol = $this->lastOf($volumes);
        if ($sma === null || $vol === null || abs($sma) < self::EPSILON_ABS) {
            return null;
        }

        return $vol / $sma;
    }

    /**
     * @param  list<?float>|list<float>  $series
     */
    private function lastOf(array $series): ?float
    {
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i] !== null) {
                return (float) $series[$i];
            }
        }

        return null;
    }

    public static function floatsEqual(float $a, float $b): bool
    {
        $diff = abs($a - $b);
        if ($diff <= self::EPSILON_ABS) {
            return true;
        }
        $scale = max(abs($a), abs($b), 1.0);

        return ($diff / $scale) <= self::EPSILON_REL;
    }
}
