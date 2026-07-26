<?php

namespace App\Services;

use App\Services\PatternDetection\CandlestickSingleBarDetector;
use App\Services\PatternDetection\CandlestickThreeBarDetector;
use App\Services\PatternDetection\CandlestickTwoBarDetector;
use App\Services\PatternDetection\ChartContinuationDetector;
use App\Services\PatternDetection\ChartReversalDetector;
use App\Services\PatternDetection\PatternDetectorInterface;

/**
 * Orchestrator for candlestick/chart pattern scanning.
 *
 * TD-004: the individual detection formulas used to live as ~25 private
 * methods on this class (~763 lines). They now live in grouped detector
 * classes under App\Services\PatternDetection (candlestick single/two/three
 * bar families, chart reversal, chart continuation), each implementing
 * PatternDetectorInterface. This class only owns pattern metadata and
 * dispatches scanBars()/detect() to the registered detectors — the public
 * API and detection behaviour are unchanged.
 */
class PatternDetectionService
{
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

    /** @var array<string, PatternDetectorInterface> pattern id => owning detector */
    private array $detectorsByPatternId = [];

    public function __construct()
    {
        $this->registerDetectors([
            new CandlestickSingleBarDetector,
            new CandlestickTwoBarDetector,
            new CandlestickThreeBarDetector,
            new ChartReversalDetector,
            new ChartContinuationDetector,
        ]);
    }

    /** @param  list<PatternDetectorInterface>  $detectors */
    private function registerDetectors(array $detectors): void
    {
        foreach ($detectors as $detector) {
            foreach ($detector->ids() as $patternId) {
                $this->detectorsByPatternId[$patternId] = $detector;
            }
        }
    }

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
        $detector = $this->detectorsByPatternId[$id] ?? null;

        return $detector !== null && $detector->detect($bars, $endIdx, $id);
    }
}
