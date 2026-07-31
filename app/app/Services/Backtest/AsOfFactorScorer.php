<?php

namespace App\Services\Backtest;

use App\Models\StockPrice;
use App\Services\Screener\TechnicalIndicatorService;

/**
 * As-of evaluation factor scorer for historical simulation.
 * Mirrors EvaluationEngine::evaluateCandidate facts without live Discovery patterns.
 */
class AsOfFactorScorer
{
    public function __construct(
        protected TechnicalIndicatorService $indicators,
    ) {}

    /**
     * @param  array<string, mixed>  $evalConfig  sma_fast, sma_slow, rsi_period, atr_period, volume_sma_period, min_bars
     * @return array{score: float, confidence: float, indicator_scores: array<string, float>, indicators: array<string, mixed>, skipped: bool}
     */
    public function score(int $stockId, string $asOfDate, array $evalConfig = []): array
    {
        $minBars = (int) ($evalConfig['min_bars'] ?? 60);
        $bars = $this->loadBars($stockId, $asOfDate, 400);
        if (count($bars) < $minBars) {
            return [
                'score' => 0.0,
                'confidence' => 0.0,
                'indicator_scores' => [],
                'indicators' => ['close' => null],
                'skipped' => true,
            ];
        }

        $ti = $this->indicators->withBars($bars);
        $smaFastPeriod = (int) ($evalConfig['sma_fast'] ?? 20);
        $smaSlowPeriod = (int) ($evalConfig['sma_slow'] ?? 50);
        $rsiPeriod = (int) ($evalConfig['rsi_period'] ?? 14);
        $atrPeriod = (int) ($evalConfig['atr_period'] ?? 14);
        $volPeriod = (int) ($evalConfig['volume_sma_period'] ?? 20);

        $close = $ti->evaluate(['indicator' => 'close']);
        $smaFast = $ti->evaluate(['indicator' => 'sma', 'params' => ['period' => $smaFastPeriod]]);
        $smaSlow = $ti->evaluate(['indicator' => 'sma', 'params' => ['period' => $smaSlowPeriod]]);
        $rsi = $ti->evaluate(['indicator' => 'rsi', 'params' => ['period' => $rsiPeriod]]);
        $atr = $ti->evaluate(['indicator' => 'atr', 'params' => ['period' => $atrPeriod]]);
        $volumeRatio = $ti->evaluate(['indicator' => 'volume_ratio', 'params' => ['period' => $volPeriod]]);
        $priceVsSma = $ti->evaluate(['indicator' => 'price_vs_sma_pct', 'params' => ['period' => $smaFastPeriod]]);

        $rs = $this->relativeStrengthProxy($bars);

        $passed = 0;
        $failed = 0;

        $trendScore = 20.0;
        if ($close !== null && $smaFast !== null && $smaSlow !== null) {
            if ($close > $smaFast && $smaFast > $smaSlow) {
                $trendScore = 100.0;
                $passed++;
            } elseif ($close > $smaFast) {
                $trendScore = 60.0;
                $passed++;
            } else {
                $failed++;
            }
        } else {
            $failed++;
        }

        $momentumScore = 50.0;
        if ($rsi !== null) {
            if ($rsi >= 45 && $rsi <= 70) {
                $momentumScore = 100.0;
                $passed++;
            } elseif ($rsi > 70) {
                $momentumScore = 55.0;
                $failed++;
            } elseif ($rsi < 30) {
                $momentumScore = 35.0;
                $failed++;
            }
        } else {
            $failed++;
        }

        $rsScore = 50.0;
        if ($rs !== null) {
            if ($rs >= 1.05) {
                $rsScore = 100.0;
                $passed++;
            } elseif ($rs >= 1.0) {
                $rsScore = 70.0;
                $passed++;
            } else {
                $rsScore = 30.0;
                $failed++;
            }
        } else {
            $failed++;
        }

        $volumeScore = 50.0;
        if ($volumeRatio !== null) {
            if ($volumeRatio >= 1.2) {
                $volumeScore = 100.0;
                $passed++;
            } elseif ($volumeRatio >= 0.8) {
                $volumeScore = 60.0;
            } else {
                $volumeScore = 30.0;
                $failed++;
            }
        }

        // Historical sim: no live discovery patterns → neutral breakout fact.
        $breakoutScore = 50.0;

        $atrPct = ($close && $atr && $close > 0) ? round(($atr / $close) * 100, 4) : null;
        $riskScore = 50.0;
        if ($atrPct !== null) {
            $riskScore = round(max(0.0, min(100.0, $atrPct * 10.0)), 4);
        }

        $factorScores = [
            'relative_strength' => $rsScore,
            'momentum_score' => $momentumScore,
            'trend_score' => $trendScore,
            'breakout_score' => $breakoutScore,
            'volume_score' => $volumeScore,
            'market_regime' => 50.0,
            'sector_strength' => 50.0,
            'risk_score' => $riskScore,
            'momentum' => $momentumScore,
            'trend' => $trendScore,
            'pattern_bonus' => $breakoutScore,
            'volume' => $volumeScore,
            'risk' => $riskScore,
        ];

        $catalogueKeys = [
            'relative_strength', 'momentum_score', 'trend_score', 'breakout_score',
            'volume_score', 'market_regime', 'sector_strength', 'risk_score',
        ];
        $present = [];
        foreach ($catalogueKeys as $k) {
            $present[] = $factorScores[$k];
        }
        $score = round(array_sum($present) / count($present), 4);
        $confidence = round(min(1.0, $passed / max(1, $passed + $failed)), 4);

        return [
            'score' => $score,
            'confidence' => $confidence,
            'indicator_scores' => array_intersect_key($factorScores, array_flip($catalogueKeys)),
            'factor_scores' => $factorScores,
            'indicators' => [
                'close' => $this->safeFloat($close),
                'sma_fast' => $this->safeFloat($smaFast),
                'sma_slow' => $this->safeFloat($smaSlow),
                'rsi' => $this->safeFloat($rsi),
                'atr' => $this->safeFloat($atr),
                'atr_pct' => $this->safeFloat($atrPct),
                'volume_ratio' => $this->safeFloat($volumeRatio),
                'price_vs_sma_pct' => $this->safeFloat($priceVsSma),
                'relative_strength_3m' => $this->safeFloat($rs),
            ],
            'skipped' => false,
        ];
    }

    /**
     * Simple 63-session price relative strength proxy (stock return factor vs 1.0 baseline).
     *
     * @param  list<array{close: float}>  $bars
     */
    private function relativeStrengthProxy(array $bars): ?float
    {
        $n = count($bars);
        if ($n < 64) {
            return null;
        }
        $now = (float) ($bars[$n - 1]['close'] ?? 0);
        $then = (float) ($bars[$n - 64]['close'] ?? 0);
        if ($then <= 0 || $now <= 0) {
            return null;
        }

        return round($now / $then, 6);
    }

    /**
     * @return list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>
     */
    private function loadBars(int $stockId, string $asOfDate, int $limit): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stockId)
            ->where('price_date', '<=', $asOfDate)
            ->whereNotNull('close_price')
            ->orderByDesc('price_date')
            ->limit($limit)
            ->get(['open_price', 'high_price', 'low_price', 'close_price', 'volume']);

        $bars = [];
        foreach ($rows->reverse()->values() as $row) {
            $close = $this->safeFloat($row->close_price);
            if ($close === null) {
                continue;
            }
            $bars[] = [
                'open' => $this->safeFloat($row->open_price),
                'high' => $this->safeFloat($row->high_price),
                'low' => $this->safeFloat($row->low_price),
                'close' => $close,
                'volume' => $this->safeFloat($row->volume),
            ];
        }

        return $bars;
    }

    private function safeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        if (! is_finite($f)) {
            return null;
        }

        return $f;
    }
}
