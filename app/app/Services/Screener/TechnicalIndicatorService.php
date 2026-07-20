<?php

namespace App\Services\Screener;

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

    /**
     * @param  list<array{open:?float,high:?float,low:?float,close:?float,volume:?float}>  $bars
     */
    public function withBars(array $bars): self
    {
        $clone = clone $this;
        $clone->bars = $bars;
        $clone->cache = [];

        return $clone;
    }

    /** @var list<array{open:?float,high:?float,low:?float,close:?float,volume:?float}> */
    private array $bars = [];

    public function clearCache(): void
    {
        $this->cache = [];
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
            'sma' => $this->lastOf($this->sma($closes, (int) ($params['period'] ?? 20))),
            'ema' => $this->lastOf($this->ema($closes, (int) ($params['period'] ?? 20))),
            'price_vs_sma_pct' => $this->priceVsMaPct('sma', (int) ($params['period'] ?? 20)),
            'price_vs_ema_pct' => $this->priceVsMaPct('ema', (int) ($params['period'] ?? 20)),
            'sma_spread_pct' => $this->maSpreadPct('sma', (int) ($params['fast'] ?? 20), (int) ($params['slow'] ?? 50)),
            'ema_spread_pct' => $this->maSpreadPct('ema', (int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26)),
            'rsi' => $this->lastOf($this->rsi($closes, (int) ($params['period'] ?? 14))),
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
            'bb_mid' => $this->lastOf($this->sma($closes, (int) ($params['period'] ?? 20))),
            'bb_upper' => $this->bbBand('upper', (int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_lower' => $this->bbBand('lower', (int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_pct_b' => $this->bbPctB((int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'bb_width_pct' => $this->bbWidthPct((int) ($params['period'] ?? 20), (float) ($params['mult'] ?? 2)),
            'volume_sma' => $this->lastOf($this->sma($this->series('volume'), (int) ($params['period'] ?? 20))),
            'volume_ratio' => $this->volumeRatio((int) ($params['period'] ?? 20)),
            default => null,
        };
    }

    /**
     * @return list<float>
     */
    private function series(string $field): array
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
        $ma = $kind === 'ema' ? $this->ema($closes, $period) : $this->sma($closes, $period);
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
        $fastSeries = $kind === 'ema' ? $this->ema($closes, $fast) : $this->sma($closes, $fast);
        $slowSeries = $kind === 'ema' ? $this->ema($closes, $slow) : $this->sma($closes, $slow);
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
     * @return list<?float>
     */
    public function stochK(int $period): array
    {
        $bars = $this->validBars();
        $period = max(1, $period);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        for ($i = $period - 1; $i < $n; $i++) {
            $slice = array_slice($bars, $i - $period + 1, $period);
            $high = max(array_map(fn ($b) => $b['high'] ?? $b['close'], $slice));
            $low = min(array_map(fn ($b) => $b['low'] ?? $b['close'], $slice));
            $range = $high - $low;
            if (abs($range) < self::EPSILON_ABS) {
                $out[$i] = null;
            } else {
                $out[$i] = (($bars[$i]['close'] - $low) / $range) * 100.0;
            }
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    public function stochD(int $period, int $smooth): array
    {
        $k = $this->stochK($period);
        $smooth = max(1, $smooth);
        $n = count($k);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($i < $smooth - 1) {
                continue;
            }
            $window = array_slice($k, $i - $smooth + 1, $smooth);
            if (in_array(null, $window, true)) {
                continue;
            }
            $out[$i] = array_sum($window) / $smooth;
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    public function macdLine(int $fast, int $slow): array
    {
        $closes = $this->series('close');
        $fastEma = $this->ema($closes, $fast);
        $slowEma = $this->ema($closes, $slow);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($fastEma[$i] === null || $slowEma[$i] === null) {
                continue;
            }
            $out[$i] = $fastEma[$i] - $slowEma[$i];
        }

        return $out;
    }

    /**
     * @return list<?float>
     */
    public function macdSignal(int $fast, int $slow, int $signal): array
    {
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
        $midSeries = $this->sma($closes, $period);
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
