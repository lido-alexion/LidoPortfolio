<?php

namespace App\Services\Indicators;

/**
 * OHLCV calculators for Liquidity / Tradability Primaries and Composites.
 *
 * Exposed for Screeners (via TechnicalIndicatorService) and future Discovery /
 * Dashboard / Stock Details consumers. Not wired into Strategy scoring or
 * Recommendation Engine (SD-033 / Epic 4 constraints).
 */
final class LiquidityTradabilityCalculator
{
    public const EPSILON = 1e-9;

    /**
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function averageVolumeSeries(array $bars, int $period): array
    {
        $period = max(1, $period);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        $sum = 0.0;
        $count = 0;
        $queue = [];
        for ($i = 0; $i < $n; $i++) {
            $vol = $bars[$i]['volume'] ?? null;
            $queue[] = $vol;
            if ($vol !== null) {
                $sum += $vol;
                $count++;
            }
            if (count($queue) > $period) {
                $old = array_shift($queue);
                if ($old !== null) {
                    $sum -= $old;
                    $count--;
                }
            }
            if (count($queue) === $period && $count === $period) {
                $out[$i] = $sum / $period;
            }
        }

        return $out;
    }

    /**
     * Daily turnover = close × volume; then SMA over period.
     *
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function averageTurnoverSeries(array $bars, int $period): array
    {
        $period = max(1, $period);
        $n = count($bars);
        $daily = [];
        for ($i = 0; $i < $n; $i++) {
            $vol = $bars[$i]['volume'] ?? null;
            $daily[] = ($vol === null) ? null : ((float) $bars[$i]['close'] * (float) $vol);
        }

        return $this->smaNullable($daily, $period);
    }

    /**
     * Short-period average turnover / baseline-period average turnover (self-relative V1).
     *
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function relativeTurnoverSeries(array $bars, int $period, int $baseline): array
    {
        $period = max(1, $period);
        $baseline = max($period, $baseline);
        $short = $this->averageTurnoverSeries($bars, $period);
        $long = $this->averageTurnoverSeries($bars, $baseline);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($short[$i] === null || $long[$i] === null || abs($long[$i]) < self::EPSILON) {
                continue;
            }
            $out[$i] = $short[$i] / $long[$i];
        }

        return $out;
    }

    /**
     * Rolling gap frequency over lookback: gaps / sessions in window.
     *
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function gapFrequencySeries(array $bars, int $period, float $thresholdPct = 1.0): array
    {
        $period = max(2, $period);
        $events = $this->gapEvents($bars, $thresholdPct);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            if ($i + 1 < $period) {
                continue;
            }
            $from = $i - $period + 1;
            $gaps = 0;
            for ($j = max(1, $from); $j <= $i; $j++) {
                if ($events[$j]['is_gap'] ?? false) {
                    $gaps++;
                }
            }
            $sessions = $i - max(1, $from) + 1;
            $out[$i] = $sessions > 0 ? $gaps / $sessions : null;
        }

        return $out;
    }

    /**
     * Fraction of gaps in lookback that filled within fill_window sessions.
     *
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function gapFillRatioSeries(array $bars, int $period, float $thresholdPct = 1.0, int $fillWindow = 5): array
    {
        $period = max(2, $period);
        $fillWindow = max(1, $fillWindow);
        $events = $this->gapEvents($bars, $thresholdPct);
        $n = count($bars);
        $out = array_fill(0, $n, null);

        for ($i = 1; $i < $n; $i++) {
            if ($i + 1 < $period) {
                continue;
            }
            $from = $i - $period + 1;
            $gapCount = 0;
            $filled = 0;
            for ($j = max(1, $from); $j <= $i; $j++) {
                if (! ($events[$j]['is_gap'] ?? false)) {
                    continue;
                }
                $gapCount++;
                if ($this->gapFilled($bars, $j, $events[$j], $fillWindow, $i)) {
                    $filled++;
                }
            }
            $out[$i] = $gapCount > 0 ? $filled / $gapCount : null;
        }

        return $out;
    }

    /**
     * Heuristic circuit-like session rate (no exchange circuit feed).
     * Session flagged when |Δclose| ≥ movePct and daily range ≤ rangePct of close.
     *
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function circuitFrequencySeries(
        array $bars,
        int $period,
        float $movePct = 9.5,
        float $rangePct = 0.5,
    ): array {
        $period = max(2, $period);
        $flags = $this->circuitFlags($bars, $movePct, $rangePct);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            if ($i + 1 < $period) {
                continue;
            }
            $from = $i - $period + 1;
            $hits = 0;
            $sessions = 0;
            for ($j = max(1, $from); $j <= $i; $j++) {
                $sessions++;
                if ($flags[$j] ?? false) {
                    $hits++;
                }
            }
            $out[$i] = $sessions > 0 ? $hits / $sessions : null;
        }

        return $out;
    }

    /**
     * 0–100 severity from circuit frequency and average absolute move on flagged days.
     *
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return list<?float>
     */
    public function circuitRiskSeries(
        array $bars,
        int $period,
        float $movePct = 9.5,
        float $rangePct = 0.5,
    ): array {
        $freq = $this->circuitFrequencySeries($bars, $period, $movePct, $rangePct);
        $flags = $this->circuitFlags($bars, $movePct, $rangePct);
        $n = count($bars);
        $out = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            if ($freq[$i] === null) {
                continue;
            }
            $from = $i - max(2, $period) + 1;
            $moveSum = 0.0;
            $moveCount = 0;
            for ($j = max(1, $from); $j <= $i; $j++) {
                if (! ($flags[$j] ?? false)) {
                    continue;
                }
                $prev = (float) $bars[$j - 1]['close'];
                $cur = (float) $bars[$j]['close'];
                if (abs($prev) < self::EPSILON) {
                    continue;
                }
                $moveSum += abs(($cur - $prev) / $prev) * 100.0;
                $moveCount++;
            }
            $avgMove = $moveCount > 0 ? $moveSum / $moveCount : $movePct;
            $out[$i] = min(100.0, max(0.0, ($freq[$i] * 70.0) + min(30.0, $avgMove)));
        }

        return $out;
    }

    /**
     * Composite 0–100 liquidity score from last available primary values.
     *
     * @param  array{relative_turnover:?float,average_turnover:?float,average_volume:?float}  $parts
     */
    public function liquidityScore(array $parts): ?float
    {
        $scores = [];
        if (isset($parts['relative_turnover']) && $parts['relative_turnover'] !== null) {
            $scores[] = min(100.0, max(0.0, (float) $parts['relative_turnover'] * 50.0));
        }
        if (isset($parts['average_turnover']) && $parts['average_turnover'] !== null && $parts['average_turnover'] > 0) {
            $scores[] = min(100.0, max(0.0, (log10((float) $parts['average_turnover'] + 1.0) / 9.0) * 100.0));
        }
        if (isset($parts['average_volume']) && $parts['average_volume'] !== null && $parts['average_volume'] > 0) {
            $scores[] = min(100.0, max(0.0, (log10((float) $parts['average_volume'] + 1.0) / 8.0) * 100.0));
        }

        return $scores === [] ? null : array_sum($scores) / count($scores);
    }

    /**
     * Composite 0–100 tradability (higher = easier to trade).
     *
     * @param  array{
     *   gap_frequency:?float,
     *   gap_fill_ratio:?float,
     *   circuit_frequency:?float,
     *   circuit_risk:?float
     * }  $parts
     */
    public function tradabilityScore(array $parts): ?float
    {
        $scores = [];
        if (isset($parts['gap_frequency']) && $parts['gap_frequency'] !== null) {
            $scores[] = 100.0 * (1.0 - min(1.0, max(0.0, (float) $parts['gap_frequency'])));
        }
        if (isset($parts['gap_fill_ratio']) && $parts['gap_fill_ratio'] !== null) {
            $scores[] = 100.0 * min(1.0, max(0.0, (float) $parts['gap_fill_ratio']));
        }
        if (isset($parts['circuit_frequency']) && $parts['circuit_frequency'] !== null) {
            $scores[] = 100.0 * (1.0 - min(1.0, max(0.0, (float) $parts['circuit_frequency'])));
        }
        if (isset($parts['circuit_risk']) && $parts['circuit_risk'] !== null) {
            $scores[] = 100.0 - min(100.0, max(0.0, (float) $parts['circuit_risk']));
        }

        return $scores === [] ? null : array_sum($scores) / count($scores);
    }

    /**
     * @param  list<?float>  $values
     * @return list<?float>
     */
    private function smaNullable(array $values, int $period): array
    {
        $period = max(1, $period);
        $n = count($values);
        $out = array_fill(0, $n, null);
        $sum = 0.0;
        $count = 0;
        $queue = [];
        for ($i = 0; $i < $n; $i++) {
            $v = $values[$i];
            $queue[] = $v;
            if ($v !== null) {
                $sum += $v;
                $count++;
            }
            if (count($queue) > $period) {
                $old = array_shift($queue);
                if ($old !== null) {
                    $sum -= $old;
                    $count--;
                }
            }
            if (count($queue) === $period && $count === $period) {
                $out[$i] = $sum / $period;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return array<int, array{is_gap:bool,direction:?string,prior_close:?float}>
     */
    private function gapEvents(array $bars, float $thresholdPct): array
    {
        $threshold = max(0.0, $thresholdPct) / 100.0;
        $n = count($bars);
        $events = [];
        for ($i = 0; $i < $n; $i++) {
            $events[$i] = ['is_gap' => false, 'direction' => null, 'prior_close' => null];
            if ($i === 0) {
                continue;
            }
            $open = $bars[$i]['open'] ?? null;
            $prior = (float) $bars[$i - 1]['close'];
            if ($open === null || abs($prior) < self::EPSILON) {
                continue;
            }
            $open = (float) $open;
            if ($open > $prior * (1.0 + $threshold)) {
                $events[$i] = ['is_gap' => true, 'direction' => 'up', 'prior_close' => $prior];
            } elseif ($open < $prior * (1.0 - $threshold)) {
                $events[$i] = ['is_gap' => true, 'direction' => 'down', 'prior_close' => $prior];
            }
        }

        return $events;
    }

    /**
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @param  array{is_gap:bool,direction:?string,prior_close:?float}  $event
     */
    private function gapFilled(array $bars, int $gapIndex, array $event, int $fillWindow, int $asOf): bool
    {
        $prior = $event['prior_close'] ?? null;
        $direction = $event['direction'] ?? null;
        if ($prior === null || $direction === null) {
            return false;
        }
        $end = min($asOf, $gapIndex + $fillWindow);
        for ($k = $gapIndex; $k <= $end; $k++) {
            $high = $bars[$k]['high'] ?? $bars[$k]['close'];
            $low = $bars[$k]['low'] ?? $bars[$k]['close'];
            if ($direction === 'up' && $low <= $prior) {
                return true;
            }
            if ($direction === 'down' && $high >= $prior) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>  $bars
     * @return array<int, bool>
     */
    private function circuitFlags(array $bars, float $movePct, float $rangePct): array
    {
        $move = max(0.0, $movePct) / 100.0;
        $range = max(0.0, $rangePct) / 100.0;
        $n = count($bars);
        $flags = array_fill(0, $n, false);
        for ($i = 1; $i < $n; $i++) {
            $prev = (float) $bars[$i - 1]['close'];
            $close = (float) $bars[$i]['close'];
            if (abs($prev) < self::EPSILON || abs($close) < self::EPSILON) {
                continue;
            }
            $chg = abs(($close - $prev) / $prev);
            $high = $bars[$i]['high'] ?? $close;
            $low = $bars[$i]['low'] ?? $close;
            $dayRange = abs(((float) $high - (float) $low) / $close);
            if ($chg >= $move && $dayRange <= $range) {
                $flags[$i] = true;
            }
        }

        return $flags;
    }
}
