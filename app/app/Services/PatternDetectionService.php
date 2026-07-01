<?php

namespace App\Services;

class PatternDetectionService
{
    private const TREND_LOOKBACK = 5;

    private const TREND_THRESHOLD = 0.03;

    private const EPS = 0.03;

    /** @var array<string, array{name: string, category: string, variant: string}> */
    private const PATTERN_META = [
        'doji' => ['name' => 'Doji', 'category' => 'neutral', 'variant' => 'candle'],
        'hammer' => ['name' => 'Hammer', 'category' => 'bullish', 'variant' => 'candle'],
        'inverted_hammer' => ['name' => 'Inverted Hammer', 'category' => 'bullish', 'variant' => 'candle'],
        'hanging_man' => ['name' => 'Hanging Man', 'category' => 'bearish', 'variant' => 'candle'],
        'shooting_star' => ['name' => 'Shooting Star', 'category' => 'bearish', 'variant' => 'candle'],
        'bullish_marubozu' => ['name' => 'Bullish Marubozu', 'category' => 'bullish', 'variant' => 'candle'],
        'bearish_marubozu' => ['name' => 'Bearish Marubozu', 'category' => 'bearish', 'variant' => 'candle'],
        'spinning_top' => ['name' => 'Spinning Top', 'category' => 'neutral', 'variant' => 'candle'],
        'bullish_engulfing' => ['name' => 'Bullish Engulfing', 'category' => 'bullish', 'variant' => 'candle'],
        'bearish_engulfing' => ['name' => 'Bearish Engulfing', 'category' => 'bearish', 'variant' => 'candle'],
        'bullish_harami' => ['name' => 'Bullish Harami', 'category' => 'bullish', 'variant' => 'candle'],
        'bearish_harami' => ['name' => 'Bearish Harami', 'category' => 'bearish', 'variant' => 'candle'],
        'piercing_line' => ['name' => 'Piercing Line', 'category' => 'bullish', 'variant' => 'candle'],
        'dark_cloud_cover' => ['name' => 'Dark Cloud Cover', 'category' => 'bearish', 'variant' => 'candle'],
        'morning_star' => ['name' => 'Morning Star', 'category' => 'bullish', 'variant' => 'candle'],
        'evening_star' => ['name' => 'Evening Star', 'category' => 'bearish', 'variant' => 'candle'],
        'three_white_soldiers' => ['name' => 'Three White Soldiers', 'category' => 'bullish', 'variant' => 'candle'],
        'three_black_crows' => ['name' => 'Three Black Crows', 'category' => 'bearish', 'variant' => 'candle'],
        'double_top' => ['name' => 'Double Top', 'category' => 'bearish_reversal', 'variant' => 'chart'],
        'double_bottom' => ['name' => 'Double Bottom', 'category' => 'bullish_reversal', 'variant' => 'chart'],
        'ascending_triangle' => ['name' => 'Ascending Triangle', 'category' => 'bullish_continuation', 'variant' => 'chart'],
        'descending_triangle' => ['name' => 'Descending Triangle', 'category' => 'bearish_continuation', 'variant' => 'chart'],
        'bull_flag' => ['name' => 'Bull Flag', 'category' => 'bullish_continuation', 'variant' => 'chart'],
        'bear_flag' => ['name' => 'Bear Flag', 'category' => 'bearish_continuation', 'variant' => 'chart'],
        'head_and_shoulders' => ['name' => 'Head and Shoulders', 'category' => 'bearish_reversal', 'variant' => 'chart'],
        'inverse_head_and_shoulders' => ['name' => 'Inverse Head and Shoulders', 'category' => 'bullish_reversal', 'variant' => 'chart'],
    ];

    /**
     * @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars
     * @return list<array{id: string, name: string, category: string, variant: string, bar_date: string}>
     */
    public function scanBars(array $bars, bool $actionableOnly = true): array
    {
        if ($bars === []) {
            return [];
        }

        $endIdx = count($bars) - 1;
        $hits = [];

        foreach (array_keys(self::PATTERN_META) as $patternId) {
            if ($this->detect($bars, $endIdx, $patternId)) {
                $meta = self::PATTERN_META[$patternId];
                if ($actionableOnly && $meta['category'] === 'neutral') {
                    continue;
                }
                $hits[] = [
                    'id' => $patternId,
                    'name' => $meta['name'],
                    'category' => $meta['category'],
                    'variant' => $meta['variant'],
                    'bar_date' => $bars[$endIdx]['date'],
                ];
            }
        }

        return $hits;
    }

    /**
     * @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars
     */
    private function detect(array $bars, int $endIdx, string $id): bool
    {
        return match ($id) {
            'doji' => $this->detectDoji($bars, $endIdx),
            'hammer' => $this->detectHammer($bars, $endIdx),
            'inverted_hammer' => $this->detectInvertedHammer($bars, $endIdx),
            'hanging_man' => $this->detectHangingMan($bars, $endIdx),
            'shooting_star' => $this->detectShootingStar($bars, $endIdx),
            'bullish_marubozu' => $this->detectBullishMarubozu($bars, $endIdx),
            'bearish_marubozu' => $this->detectBearishMarubozu($bars, $endIdx),
            'spinning_top' => $this->detectSpinningTop($bars, $endIdx),
            'bullish_engulfing' => $this->detectBullishEngulfing($bars, $endIdx),
            'bearish_engulfing' => $this->detectBearishEngulfing($bars, $endIdx),
            'bullish_harami' => $this->detectBullishHarami($bars, $endIdx),
            'bearish_harami' => $this->detectBearishHarami($bars, $endIdx),
            'piercing_line' => $this->detectPiercingLine($bars, $endIdx),
            'dark_cloud_cover' => $this->detectDarkCloudCover($bars, $endIdx),
            'morning_star' => $this->detectMorningStar($bars, $endIdx),
            'evening_star' => $this->detectEveningStar($bars, $endIdx),
            'three_white_soldiers' => $this->detectThreeWhiteSoldiers($bars, $endIdx),
            'three_black_crows' => $this->detectThreeBlackCrows($bars, $endIdx),
            'double_top' => $this->detectDoubleTop($bars, $endIdx),
            'double_bottom' => $this->detectDoubleBottom($bars, $endIdx),
            'ascending_triangle' => $this->detectAscendingTriangle($bars, $endIdx),
            'descending_triangle' => $this->detectDescendingTriangle($bars, $endIdx),
            'bull_flag' => $this->detectBullFlag($bars, $endIdx),
            'bear_flag' => $this->detectBearFlag($bars, $endIdx),
            'head_and_shoulders' => $this->detectHeadAndShoulders($bars, $endIdx),
            'inverse_head_and_shoulders' => $this->detectInverseHeadAndShoulders($bars, $endIdx),
            default => false,
        };
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function metrics(array $bars, int $idx): array
    {
        $bar = $bars[$idx];
        $close = $bar['close'];
        $open = $bar['open'];
        $high = $bar['high'];
        $low = $bar['low'];
        $range = $high - $low;
        $body = abs($close - $open);
        $bodySigned = $close - $open;
        $upperWick = $high - max($open, $close);
        $lowerWick = min($open, $close) - $low;

        return [
            'open' => $open,
            'close' => $close,
            'high' => $high,
            'low' => $low,
            'range' => $range,
            'body' => $body,
            'body_signed' => $bodySigned,
            'upper_wick' => $upperWick,
            'lower_wick' => $lowerWick,
            'body_ratio' => $range > 0 ? $body / $range : 0.0,
            'close_position' => $range > 0 ? ($close - $low) / $range : 0.5,
        ];
    }

    /** @param  list<array{close: float}>  $bars */
    private function priorTrendPct(array $bars, int $endIdx): float
    {
        if ($endIdx < self::TREND_LOOKBACK) {
            return 0.0;
        }
        $start = $bars[$endIdx - self::TREND_LOOKBACK]['close'];
        $end = $bars[$endIdx - 1]['close'];
        if ($start <= 0) {
            return 0.0;
        }

        return ($end - $start) / $start;
    }

    /** @param  list<array{close: float}>  $bars */
    private function isDowntrend(array $bars, int $endIdx): bool
    {
        return $this->priorTrendPct($bars, $endIdx) <= -self::TREND_THRESHOLD;
    }

    /** @param  list<array{close: float}>  $bars */
    private function isUptrend(array $bars, int $endIdx): bool
    {
        return $this->priorTrendPct($bars, $endIdx) >= self::TREND_THRESHOLD;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function avgBody(array $bars, int $endIdx, int $lookback = 10): float
    {
        $start = max(0, $endIdx - $lookback + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $start; $i <= $endIdx; $i++) {
            $sum += $this->metrics($bars, $i)['body'];
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    private function hammerShape(array $m): bool
    {
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.35) {
            return false;
        }
        if ($m['body'] > 0 && $m['lower_wick'] < 2 * $m['body']) {
            return false;
        }
        if ($m['body'] > 0 && $m['upper_wick'] > 0.25 * $m['body']) {
            return false;
        }
        if ($m['body'] === 0.0 && $m['lower_wick'] < 0.5 * $m['range']) {
            return false;
        }

        return $m['close_position'] >= 0.60;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectDoji(array $bars, int $endIdx): bool
    {
        $m = $this->metrics($bars, $endIdx);

        return $m['range'] > 0 && $m['body_ratio'] <= 0.10;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectHammer(array $bars, int $endIdx): bool
    {
        return $this->hammerShape($this->metrics($bars, $endIdx)) && $this->isDowntrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectInvertedHammer(array $bars, int $endIdx): bool
    {
        $m = $this->metrics($bars, $endIdx);
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.35) {
            return false;
        }
        if ($m['body'] > 0 && $m['upper_wick'] < 2 * $m['body']) {
            return false;
        }
        if ($m['body'] > 0 && $m['lower_wick'] > 0.25 * $m['body']) {
            return false;
        }

        return $m['close_position'] <= 0.40 && $this->isDowntrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectHangingMan(array $bars, int $endIdx): bool
    {
        return $this->hammerShape($this->metrics($bars, $endIdx)) && $this->isUptrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectShootingStar(array $bars, int $endIdx): bool
    {
        $m = $this->metrics($bars, $endIdx);
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.35) {
            return false;
        }
        if ($m['body'] > 0 && $m['upper_wick'] < 2 * $m['body']) {
            return false;
        }
        if ($m['body'] > 0 && $m['lower_wick'] > 0.25 * $m['body']) {
            return false;
        }

        return $m['close_position'] <= 0.35 && $this->isUptrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullishMarubozu(array $bars, int $endIdx): bool
    {
        $m = $this->metrics($bars, $endIdx);

        return $m['body_signed'] > 0
            && $m['body_ratio'] >= 0.90
            && $m['upper_wick'] <= 0.05 * $m['range']
            && $m['lower_wick'] <= 0.05 * $m['range'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearishMarubozu(array $bars, int $endIdx): bool
    {
        $m = $this->metrics($bars, $endIdx);

        return $m['body_signed'] < 0
            && $m['body_ratio'] >= 0.90
            && $m['upper_wick'] <= 0.05 * $m['range']
            && $m['lower_wick'] <= 0.05 * $m['range'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectSpinningTop(array $bars, int $endIdx): bool
    {
        $m = $this->metrics($bars, $endIdx);
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.30) {
            return false;
        }
        if ($m['upper_wick'] < 0.25 * $m['range'] || $m['lower_wick'] < 0.25 * $m['range']) {
            return false;
        }

        return abs($m['upper_wick'] - $m['lower_wick']) <= 0.20 * $m['range'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullishEngulfing(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 1);
        $m1 = $this->metrics($bars, $endIdx);
        if ($m0['body_signed'] >= 0 || $m1['body_signed'] <= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 <= $min0 && $max1 >= $max0 && $m1['body'] > $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearishEngulfing(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 1);
        $m1 = $this->metrics($bars, $endIdx);
        if ($m0['body_signed'] <= 0 || $m1['body_signed'] >= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 <= $min0 && $max1 >= $max0 && $m1['body'] > $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullishHarami(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 1);
        $m1 = $this->metrics($bars, $endIdx);
        if ($m0['body_signed'] >= 0 || $m1['body_signed'] <= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 > $min0 && $max1 < $max0 && $m1['body'] < $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearishHarami(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 1);
        $m1 = $this->metrics($bars, $endIdx);
        if ($m0['body_signed'] <= 0 || $m1['body_signed'] >= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 > $min0 && $max1 < $max0 && $m1['body'] < $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectPiercingLine(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1 || ! $this->isDowntrend($bars, $endIdx)) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 1);
        $m1 = $this->metrics($bars, $endIdx);
        if ($m0['body_signed'] >= 0 || $m1['body_signed'] <= 0) {
            return false;
        }
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m1['close'] > $mid && $m1['close'] < $m0['open'] && $m1['open'] <= $m0['close'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectDarkCloudCover(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1 || ! $this->isUptrend($bars, $endIdx)) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 1);
        $m1 = $this->metrics($bars, $endIdx);
        if ($m0['body_signed'] <= 0 || $m1['body_signed'] >= 0) {
            return false;
        }
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m1['close'] < $mid && $m1['close'] > $m0['close'] && $m1['open'] >= $m0['close'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectMorningStar(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = $this->avgBody($bars, $endIdx - 1);
        $m0 = $this->metrics($bars, $endIdx - 2);
        $m1 = $this->metrics($bars, $endIdx - 1);
        $m2 = $this->metrics($bars, $endIdx);
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m0['body_signed'] < 0
            && $m0['body'] >= $avg
            && $m1['body_ratio'] <= 0.35
            && $m1['close'] < $m0['close']
            && $m2['body_signed'] > 0
            && $m2['close'] > $mid;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectEveningStar(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = $this->avgBody($bars, $endIdx - 1);
        $m0 = $this->metrics($bars, $endIdx - 2);
        $m1 = $this->metrics($bars, $endIdx - 1);
        $m2 = $this->metrics($bars, $endIdx);
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m0['body_signed'] > 0
            && $m0['body'] >= $avg
            && $m1['body_ratio'] <= 0.35
            && $m1['close'] > $m0['close']
            && $m2['body_signed'] < 0
            && $m2['close'] < $mid;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectThreeWhiteSoldiers(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = $this->avgBody($bars, $endIdx);
        for ($i = $endIdx - 2; $i <= $endIdx; $i++) {
            $m = $this->metrics($bars, $i);
            if ($m['body_signed'] <= 0 || $m['body'] < 0.5 * $avg) {
                return false;
            }
        }
        $c0 = $bars[$endIdx - 2]['close'];
        $c1 = $bars[$endIdx - 1]['close'];
        $c2 = $bars[$endIdx]['close'];
        if (! ($c2 > $c1 && $c1 > $c0)) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 2);
        $m1 = $this->metrics($bars, $endIdx - 1);
        $m2 = $this->metrics($bars, $endIdx);

        return $m1['open'] >= $m0['open'] && $m1['open'] <= $m0['close']
            && $m2['open'] >= $m1['open'] && $m2['open'] <= $m1['close'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectThreeBlackCrows(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = $this->avgBody($bars, $endIdx);
        for ($i = $endIdx - 2; $i <= $endIdx; $i++) {
            $m = $this->metrics($bars, $i);
            if ($m['body_signed'] >= 0 || $m['body'] < 0.5 * $avg) {
                return false;
            }
        }
        $c0 = $bars[$endIdx - 2]['close'];
        $c1 = $bars[$endIdx - 1]['close'];
        $c2 = $bars[$endIdx]['close'];
        if (! ($c2 < $c1 && $c1 < $c0)) {
            return false;
        }
        $m0 = $this->metrics($bars, $endIdx - 2);
        $m1 = $this->metrics($bars, $endIdx - 1);
        $m2 = $this->metrics($bars, $endIdx);

        return $m1['open'] <= $m0['open'] && $m1['open'] >= $m0['close']
            && $m2['open'] <= $m1['open'] && $m2['open'] >= $m1['close'];
    }

    /** @param  list<float>  $values */
    private function linearSlope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $values[$i];
            $sumXY += $i * $values[$i];
            $sumXX += $i * $i;
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        if ($denom == 0.0) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denom;
    }

    /**
     * @param  list<array{close: float}>  $window
     * @return list<int>
     */
    private function localPeaks(array $window, int $minSeparation = 2): array
    {
        $peaks = [];
        $count = count($window);
        for ($i = 1; $i < $count - 1; $i++) {
            if ($window[$i]['close'] >= $window[$i - 1]['close']
                && $window[$i]['close'] >= $window[$i + 1]['close']) {
                if ($peaks === [] || $i - $peaks[count($peaks) - 1] >= $minSeparation) {
                    $peaks[] = $i;
                }
            }
        }

        return $peaks;
    }

    /**
     * @param  list<array{close: float}>  $window
     * @return list<int>
     */
    private function localTroughs(array $window, int $minSeparation = 2): array
    {
        $troughs = [];
        $count = count($window);
        for ($i = 1; $i < $count - 1; $i++) {
            if ($window[$i]['close'] <= $window[$i - 1]['close']
                && $window[$i]['close'] <= $window[$i + 1]['close']) {
                if ($troughs === [] || $i - $troughs[count($troughs) - 1] >= $minSeparation) {
                    $troughs[] = $i;
                }
            }
        }

        return $troughs;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectDoubleTop(array $bars, int $endIdx): bool
    {
        if ($endIdx < 9) {
            return false;
        }
        $window = array_slice($bars, max(0, $endIdx - 29), $endIdx - max(0, $endIdx - 29) + 1);
        $peaks = $this->localPeaks($window, 3);
        if (count($peaks) < 2) {
            return false;
        }
        $p1 = $peaks[count($peaks) - 2];
        $p2 = $peaks[count($peaks) - 1];
        $h1 = $window[$p1]['close'];
        $h2 = $window[$p2]['close'];
        if ($h1 <= 0 || abs($h1 - $h2) / $h1 > self::EPS) {
            return false;
        }
        $valley = INF;
        for ($i = $p1 + 1; $i < $p2; $i++) {
            $valley = min($valley, $window[$i]['close']);
        }
        if ($valley === INF || ($h1 - $valley) / $h1 < 0.03) {
            return false;
        }

        return $window[count($window) - 1]['close'] <= $valley * 1.01;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectDoubleBottom(array $bars, int $endIdx): bool
    {
        if ($endIdx < 9) {
            return false;
        }
        $window = array_slice($bars, max(0, $endIdx - 29), $endIdx - max(0, $endIdx - 29) + 1);
        $troughs = $this->localTroughs($window, 3);
        if (count($troughs) < 2) {
            return false;
        }
        $t1 = $troughs[count($troughs) - 2];
        $t2 = $troughs[count($troughs) - 1];
        $l1 = $window[$t1]['close'];
        $l2 = $window[$t2]['close'];
        if ($l1 <= 0 || abs($l1 - $l2) / $l1 > self::EPS) {
            return false;
        }
        $peak = -INF;
        for ($i = $t1 + 1; $i < $t2; $i++) {
            $peak = max($peak, $window[$i]['close']);
        }
        if ($peak === -INF || ($peak - $l1) / $l1 < 0.03) {
            return false;
        }

        return $window[count($window) - 1]['close'] >= $peak * 0.99;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectAscendingTriangle(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $highs = array_map(fn ($b) => $b['high'], $window);
        $lows = array_map(fn ($b) => $b['low'], $window);
        $highSpread = max($highs) - min($highs);
        $avgHigh = array_sum($highs) / count($highs);
        if ($avgHigh <= 0 || $highSpread / $avgHigh > 0.02) {
            return false;
        }
        if ($this->linearSlope($lows) <= 0) {
            return false;
        }
        $widthStart = $highs[0] - $lows[0];
        $widthEnd = $highs[count($highs) - 1] - $lows[count($lows) - 1];
        if ($widthEnd >= $widthStart) {
            return false;
        }

        return $window[count($window) - 1]['close'] > max($highs) * 0.995;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectDescendingTriangle(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $highs = array_map(fn ($b) => $b['high'], $window);
        $lows = array_map(fn ($b) => $b['low'], $window);
        $lowSpread = max($lows) - min($lows);
        $avgLow = array_sum($lows) / count($lows);
        if ($avgLow <= 0 || $lowSpread / $avgLow > 0.02) {
            return false;
        }
        if ($this->linearSlope($highs) >= 0) {
            return false;
        }
        $widthStart = $highs[0] - $lows[0];
        $widthEnd = $highs[count($highs) - 1] - $lows[count($lows) - 1];
        if ($widthEnd >= $widthStart) {
            return false;
        }

        return $window[count($window) - 1]['close'] < min($lows) * 1.005;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullFlag(array $bars, int $endIdx): bool
    {
        if ($endIdx < 11) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 11, 12);
        $poleStart = $window[0]['close'];
        $poleEnd = $window[3]['close'];
        if ($poleStart <= 0 || ($poleEnd - $poleStart) / $poleStart < 0.06) {
            return false;
        }
        $flag = array_slice($window, 4);
        $flagSlope = $this->linearSlope(array_map(fn ($b) => $b['close'], $flag));
        if ($flagSlope >= 0) {
            return false;
        }
        $flagHigh = max(array_map(fn ($b) => $b['high'], $flag));

        return $window[count($window) - 1]['close'] > $flagHigh;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearFlag(array $bars, int $endIdx): bool
    {
        if ($endIdx < 11) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 11, 12);
        $poleStart = $window[0]['close'];
        $poleEnd = $window[3]['close'];
        if ($poleStart <= 0 || ($poleEnd - $poleStart) / $poleStart < -0.06) {
            return false;
        }
        $flag = array_slice($window, 4);
        $flagSlope = $this->linearSlope(array_map(fn ($b) => $b['close'], $flag));
        if ($flagSlope <= 0) {
            return false;
        }
        $flagLow = min(array_map(fn ($b) => $b['low'], $flag));

        return $window[count($window) - 1]['close'] < $flagLow;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectHeadAndShoulders(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $peaks = $this->localPeaks($window, 2);
        if (count($peaks) < 3) {
            return false;
        }
        $slice = array_slice($peaks, -3);
        [$i1, $i2, $i3] = $slice;
        $h = $window[$i2]['close'];
        $s1 = $window[$i1]['close'];
        $s2 = $window[$i3]['close'];
        if (! ($h > $s1 && $h > $s2) || $s1 <= 0 || abs($s1 - $s2) / $s1 > self::EPS) {
            return false;
        }
        $neckline = min(
            min(array_column(array_slice($window, $i1, $i2 - $i1 + 1), 'low')),
            min(array_column(array_slice($window, $i2, $i3 - $i2 + 1), 'low'))
        );

        return $window[count($window) - 1]['close'] <= $neckline;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectInverseHeadAndShoulders(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $troughs = $this->localTroughs($window, 2);
        if (count($troughs) < 3) {
            return false;
        }
        $slice = array_slice($troughs, -3);
        [$i1, $i2, $i3] = $slice;
        $head = $window[$i2]['close'];
        $s1 = $window[$i1]['close'];
        $s2 = $window[$i3]['close'];
        if (! ($head < $s1 && $head < $s2) || $s1 <= 0 || abs($s1 - $s2) / $s1 > self::EPS) {
            return false;
        }
        $neckline = max(
            max(array_column(array_slice($window, $i1, $i2 - $i1 + 1), 'high')),
            max(array_column(array_slice($window, $i2, $i3 - $i2 + 1), 'high'))
        );

        return $window[count($window) - 1]['close'] >= $neckline;
    }
}
