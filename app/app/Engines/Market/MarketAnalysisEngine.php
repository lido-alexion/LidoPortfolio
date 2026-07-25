<?php

namespace App\Engines\Market;

use App\Models\MarketAnalyticsSnapshot;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\RelativeStrengthService;
use App\Services\Screener\TechnicalIndicatorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Market Analysis Engine (SD-032).
 * Single source of truth for market-level analytics from benchmark OHLCV.
 * Does not know portfolios or recommendations.
 */
class MarketAnalysisEngine
{
    public const PHASE_STRONG_BULL = 'Strong Bull';

    public const PHASE_BULL = 'Bull';

    public const PHASE_CONSOLIDATION = 'Consolidation';

    public const PHASE_PULLBACK = 'Pullback';

    public const PHASE_CORRECTION = 'Correction';

    public const PHASE_BEAR = 'Bear';

    public const PHASE_CAPITULATION = 'Capitulation';

    public const PHASE_RECOVERY = 'Recovery';

    public function __construct(
        protected RelativeStrengthService $relativeStrength,
        protected TechnicalIndicatorService $indicators,
    ) {}

    /**
     * Latest market analytics (compute + persist if stale/missing).
     *
     * @return array<string, mixed>
     */
    public function latest(bool $forceRefresh = false): array
    {
        $benchmark = $this->relativeStrength->benchmarkStock();
        $asOf = $this->latestBarDate($benchmark->id) ?? now()->toDateString();

        if (! $forceRefresh && Schema::hasTable('portfolio_tos_market_analytics')) {
            $existing = MarketAnalyticsSnapshot::query()
                ->where('benchmark_stock_id', $benchmark->id)
                ->where('as_of_date', $asOf)
                ->where('computed_at', '>=', now()->subHours(4))
                ->first();
            if ($existing) {
                return $this->serializeSnapshot($existing, cached: true);
            }
        }

        $payload = $this->analyze($benchmark);
        $this->persist($benchmark->id, $asOf, $payload);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $days = 90): array
    {
        if (! Schema::hasTable('portfolio_tos_market_analytics')) {
            return [];
        }
        $benchmark = $this->relativeStrength->benchmarkStock();

        return MarketAnalyticsSnapshot::query()
            ->where('benchmark_stock_id', $benchmark->id)
            ->orderByDesc('as_of_date')
            ->limit($days)
            ->get()
            ->map(fn (MarketAnalyticsSnapshot $row) => [
                'as_of_date' => optional($row->as_of_date)?->toDateString(),
                'market_phase' => $row->market_phase,
                'sentiment_score' => (float) $row->sentiment_score,
                'sentiment_label' => $row->sentiment_label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function explainability(): array
    {
        $latest = $this->latest();

        return $latest['explainability'] ?? [
            'market_phase' => $latest['market_phase'] ?? null,
            'sentiment_score' => $latest['sentiment']['score'] ?? null,
            'reasons' => [],
        ];
    }

    /**
     * Core analysis of a benchmark index.
     *
     * @return array<string, mixed>
     */
    public function analyze(Stock $benchmark): array
    {
        $cfg = config('trading_os.market_analysis', []);
        $bars = StockPrice::query()
            ->where('stock_id', $benchmark->id)
            ->orderByDesc('price_date')
            ->limit(320)
            ->get()
            ->sortBy('price_date')
            ->values();

        if ($bars->count() < 30) {
            return $this->emptyPayload($benchmark, 'Insufficient OHLCV for market analysis.');
        }

        $ohlcv = $bars->map(fn ($b) => [
            'open' => $b->open_price !== null ? (float) $b->open_price : null,
            'high' => $b->high_price !== null ? (float) $b->high_price : null,
            'low' => $b->low_price !== null ? (float) $b->low_price : null,
            'close' => (float) $b->close_price,
            'volume' => $b->volume !== null ? (float) $b->volume : null,
        ])->all();

        $tech = $this->indicators->withBars($ohlcv);
        $closes = array_column($ohlcv, 'close');
        $latest = (float) end($closes);
        $asOf = optional($bars->last()->price_date)->toDateString()
            ?? (is_string($bars->last()->price_date) ? substr((string) $bars->last()->price_date, 0, 10) : now()->toDateString());

        $sma20 = $tech->evaluate(['indicator' => 'sma', 'params' => ['period' => 20]]);
        $sma50 = $tech->evaluate(['indicator' => 'sma', 'params' => ['period' => 50]]);
        $sma200 = $tech->evaluate(['indicator' => 'sma', 'params' => ['period' => 200]]);
        $rsi = $tech->evaluate(['indicator' => 'rsi', 'params' => ['period' => 14]]);
        $atr = $tech->evaluate(['indicator' => 'atr', 'params' => ['period' => 14]]);
        $macd = $tech->evaluate(['indicator' => 'macd', 'params' => ['fast' => 12, 'slow' => 26]]);
        $macdSignal = $tech->evaluate(['indicator' => 'macd_signal', 'params' => ['fast' => 12, 'slow' => 26, 'signal' => 9]]);
        $roc = $tech->evaluate(['indicator' => 'roc', 'params' => ['period' => 12]]);
        $priceVs200 = $tech->evaluate(['indicator' => 'price_vs_sma_pct', 'params' => ['period' => 200]]);

        $atrPct = ($atr !== null && $latest > 0) ? round(($atr / $latest) * 100, 4) : null;
        $hv = $this->historicalVolatilityPct($closes, 20);
        $avgRange = $this->averageDailyRangePct($ohlcv, 14);

        $high52 = (float) $bars->max('high_price');
        $ath = max($closes);
        $drawdown = $this->drawdownMetrics($closes, $high52, $ath, $latest);

        $trend = $this->buildTrend($latest, $sma20, $sma50, $sma200, $priceVs200, $closes);
        $momentum = $this->buildMomentum($rsi, $macd, $macdSignal, $roc);
        $volatility = $this->buildVolatility($hv, $atr, $atrPct, $avgRange);
        $risk = $this->buildRisk($volatility, $drawdown, $trend, $momentum);
        $breadth = $this->buildBreadthV1($closes); // index-proxy breadth until constituent scan expands

        $weights = $cfg['sentiment_weights'] ?? [
            'trend' => 25,
            'momentum' => 20,
            'breadth' => 20,
            'risk' => 20,
            'volatility' => 15,
        ];
        $sentiment = $this->buildSentiment($trend, $momentum, $breadth, $risk, $volatility, $weights);
        $phaseInfo = $this->classifyPhase($trend, $momentum, $drawdown, $volatility, $risk, $sentiment['score']);
        $phaseName = $phaseInfo['name'];

        $explainability = [
            'market_phase' => $phaseName,
            'sentiment_score' => $sentiment['score'],
            'sentiment_label' => $sentiment['label'],
            'reasons' => [
                ['factor' => 'Trend', 'value' => $trend['label'], 'detail' => $trend['quality']],
                ['factor' => 'Momentum', 'value' => $momentum['label'], 'detail' => 'Score '.$momentum['score']],
                ['factor' => 'Risk', 'value' => $risk['label'], 'detail' => 'Score '.$risk['score']],
                ['factor' => 'Drawdown', 'value' => ($drawdown['current_drawdown_pct'] ?? '—').'%', 'detail' => 'Max '.$drawdown['maximum_drawdown_pct'].'%'],
                ['factor' => 'Volatility', 'value' => $volatility['label'], 'detail' => 'HV '.$volatility['historical_volatility_pct'].'%'],
                ['factor' => 'Breadth', 'value' => $breadth['label'], 'detail' => $breadth['note']],
                ['factor' => 'Sentiment', 'value' => (string) $sentiment['score'], 'detail' => $sentiment['label']],
            ],
            'sentiment_components' => $sentiment['components'],
            'phase_rule' => $phaseInfo['rule'] ?? null,
        ];

        return [
            'owner' => 'market_analysis_engine',
            'available' => true,
            'benchmark' => [
                'id' => $benchmark->id,
                'symbol' => $benchmark->symbol,
                'name' => $benchmark->name,
            ],
            'as_of_date' => $asOf,
            'computed_at' => now()->toIso8601String(),
            'cached' => false,
            'market_phase' => $phaseName,
            'sentiment' => $sentiment,
            'trend' => $trend,
            'momentum' => $momentum,
            'volatility' => $volatility,
            'risk' => $risk,
            'drawdown' => $drawdown,
            'breadth' => $breadth,
            'indicators' => [
                'close' => $latest,
                'sma_20' => $sma20,
                'sma_50' => $sma50,
                'sma_200' => $sma200,
                'rsi_14' => $rsi,
                'atr_14' => $atr,
                'atr_pct' => $atrPct,
                'macd' => $macd,
                'macd_signal' => $macdSignal,
                'roc_12' => $roc,
                'price_vs_sma200_pct' => $priceVs200,
                'distance_52w_high_pct' => $drawdown['distance_52w_high_pct'],
                'distance_ath_pct' => $drawdown['distance_ath_pct'],
            ],
            'market_regime' => $this->regimeFromPhase($phaseName),
            'index_trend' => $trend['direction'],
            'pct_stocks_above_50_dma' => $breadth['pct_above_50_dma'],
            'pct_stocks_above_200_dma' => $breadth['pct_above_200_dma'],
            'advance_decline_ratio' => $breadth['advance_decline_ratio'],
            'new_highs_vs_lows' => $breadth['new_highs_vs_lows'],
            'explainability' => $explainability,
            'allocation_multiplier' => $this->allocationMultiplier($phaseName, $sentiment['score'], $risk['score']),
            'new_entry_allowed' => $this->newEntryAllowed($phaseName, $risk['label']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function persist(int $benchmarkId, string $asOf, array $payload): void
    {
        if (! Schema::hasTable('portfolio_tos_market_analytics')) {
            return;
        }
        MarketAnalyticsSnapshot::query()->updateOrCreate(
            [
                'benchmark_stock_id' => $benchmarkId,
                'as_of_date' => $asOf,
            ],
            [
                'market_phase' => (string) ($payload['market_phase'] ?? 'Consolidation'),
                'sentiment_score' => (float) ($payload['sentiment']['score'] ?? 50),
                'sentiment_label' => $payload['sentiment']['label'] ?? null,
                'payload_json' => $payload,
                'explainability_json' => $payload['explainability'] ?? null,
                'computed_at' => now(),
            ]
        );
    }

    protected function serializeSnapshot(MarketAnalyticsSnapshot $row, bool $cached): array
    {
        $payload = is_array($row->payload_json) ? $row->payload_json : [];
        $payload['cached'] = $cached;
        $payload['as_of_date'] = optional($row->as_of_date)?->toDateString();

        return $payload;
    }

    protected function latestBarDate(int $stockId): ?string
    {
        $d = StockPrice::query()->where('stock_id', $stockId)->max('price_date');
        if ($d === null) {
            return null;
        }

        return Carbon::parse($d)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPayload(Stock $benchmark, string $message): array
    {
        return [
            'owner' => 'market_analysis_engine',
            'benchmark' => ['id' => $benchmark->id, 'symbol' => $benchmark->symbol, 'name' => $benchmark->name],
            'available' => false,
            'message' => $message,
            'market_phase' => 'Consolidation',
            'sentiment' => ['score' => 50, 'label' => 'Neutral', 'components' => []],
            'market_regime' => 'Neutral',
            'index_trend' => 'Unknown',
            'allocation_multiplier' => 1.0,
            'new_entry_allowed' => true,
            'explainability' => ['reasons' => [['factor' => 'Data', 'value' => 'Unavailable', 'detail' => $message]]],
            'computed_at' => now()->toIso8601String(),
            'cached' => false,
        ];
    }

    /**
     * @param  list<float>  $closes
     * @return array<string, mixed>
     */
    protected function buildTrend(float $latest, ?float $sma20, ?float $sma50, ?float $sma200, ?float $priceVs200, array $closes): array
    {
        $alignedBull = $sma20 !== null && $sma50 !== null && $sma200 !== null
            && $latest > $sma20 && $sma20 > $sma50 && $sma50 > $sma200;
        $alignedBear = $sma20 !== null && $sma50 !== null && $sma200 !== null
            && $latest < $sma20 && $sma20 < $sma50 && $sma50 < $sma200;

        $slope = null;
        if (count($closes) >= 20) {
            $a = $closes[count($closes) - 20];
            $slope = $a > 0 ? (($latest - $a) / $a) * 100 : null;
        }

        $score = 50.0;
        if ($alignedBull) {
            $score = 90;
        } elseif ($alignedBear) {
            $score = 10;
        } elseif ($sma50 !== null && $sma200 !== null) {
            $score = $sma50 > $sma200 ? 65 : 35;
        }
        if ($priceVs200 !== null) {
            $score = max(0, min(100, $score + ($priceVs200 * 0.5)));
        }

        $label = match (true) {
            $score >= 85 => 'Strong Uptrend',
            $score >= 65 => 'Uptrend',
            $score >= 45 => 'Sideways',
            $score >= 25 => 'Weak Downtrend',
            default => 'Strong Downtrend',
        };
        $direction = $score >= 55 ? 'Bullish' : ($score <= 45 ? 'Bearish' : 'Neutral');

        return [
            'label' => $label,
            'direction' => $direction,
            'strength' => round($score, 2),
            'quality' => $alignedBull ? 'MA stack aligned bullish' : ($alignedBear ? 'MA stack aligned bearish' : 'Mixed MA alignment'),
            'score' => round($score, 2),
            'slope_20d_pct' => $slope !== null ? round($slope, 2) : null,
            'distance_200_dma_pct' => $priceVs200 !== null ? round($priceVs200, 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMomentum(?float $rsi, ?float $macd, ?float $macdSignal, ?float $roc): array
    {
        $score = 50.0;
        if ($rsi !== null) {
            $score = $rsi;
        }
        if ($macd !== null && $macdSignal !== null) {
            $score += $macd > $macdSignal ? 8 : -8;
        }
        if ($roc !== null) {
            $score += max(-15, min(15, $roc));
        }
        $score = max(0, min(100, $score));
        $label = match (true) {
            $score >= 70 => 'High',
            $score >= 45 => 'Moderate',
            default => 'Low',
        };
        $direction = $score >= 55 ? 'Up' : ($score <= 45 ? 'Down' : 'Flat');

        return [
            'score' => round($score, 2),
            'label' => $label,
            'direction' => $direction,
            'strength' => $label,
            'rsi' => $rsi !== null ? round($rsi, 2) : null,
            'macd' => $macd !== null ? round($macd, 4) : null,
            'roc' => $roc !== null ? round($roc, 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildVolatility(?float $hv, ?float $atr, ?float $atrPct, ?float $avgRange): array
    {
        $ref = $hv ?? ($atrPct !== null ? $atrPct * 4 : 16);
        $label = match (true) {
            $ref >= 35 => 'Extreme',
            $ref >= 25 => 'High',
            $ref >= 15 => 'Moderate',
            default => 'Low',
        };
        // Higher vol → lower contribution score for sentiment (inverted later)
        $score = max(0, min(100, 100 - ($ref * 2)));

        return [
            'historical_volatility_pct' => $hv !== null ? round($hv, 2) : null,
            'atr' => $atr !== null ? round($atr, 4) : null,
            'atr_pct' => $atrPct !== null ? round($atrPct, 4) : null,
            'average_daily_range_pct' => $avgRange !== null ? round($avgRange, 2) : null,
            'label' => $label,
            'score' => round($score, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $volatility
     * @param  array<string, mixed>  $drawdown
     * @param  array<string, mixed>  $trend
     * @param  array<string, mixed>  $momentum
     * @return array<string, mixed>
     */
    protected function buildRisk(array $volatility, array $drawdown, array $trend, array $momentum): array
    {
        $dd = abs((float) ($drawdown['current_drawdown_pct'] ?? 0));
        $volPenalty = match ($volatility['label'] ?? 'Moderate') {
            'Extreme' => 40,
            'High' => 28,
            'Moderate' => 15,
            default => 5,
        };
        $trendFail = ($trend['score'] ?? 50) < 35 ? 25 : 0;
        $momFail = ($momentum['score'] ?? 50) < 35 ? 15 : 0;
        $rawRisk = min(100, $dd * 2 + $volPenalty + $trendFail + $momFail);
        $score = round(100 - $rawRisk, 2); // higher = safer for sentiment contribution
        $label = match (true) {
            $rawRisk >= 70 => 'Extreme',
            $rawRisk >= 50 => 'High',
            $rawRisk >= 30 => 'Medium',
            default => 'Low',
        };

        return [
            'score' => $score,
            'raw_risk' => round($rawRisk, 2),
            'label' => $label,
            'gap_risk' => ($volatility['atr_pct'] ?? 0) > 2 ? 'Elevated' : 'Normal',
            'volatility_risk' => $volatility['label'] ?? 'Moderate',
            'drawdown_risk' => $dd >= 10 ? 'Elevated' : 'Normal',
            'trend_failure_risk' => $trendFail > 0 ? 'Elevated' : 'Normal',
        ];
    }

    /**
     * V1 breadth proxy from index path persistence (constituent scan can replace later).
     *
     * @param  list<float>  $closes
     * @return array<string, mixed>
     */
    protected function buildBreadthV1(array $closes): array
    {
        $n = count($closes);
        $adv = 0;
        $dec = 0;
        $window = min(20, $n - 1);
        for ($i = $n - $window; $i < $n; $i++) {
            if ($i <= 0) {
                continue;
            }
            if ($closes[$i] > $closes[$i - 1]) {
                $adv++;
            } elseif ($closes[$i] < $closes[$i - 1]) {
                $dec++;
            }
        }
        $ratio = $dec > 0 ? round($adv / $dec, 2) : ($adv > 0 ? 2.0 : 1.0);
        $score = max(0, min(100, 50 + (($ratio - 1) * 40)));
        $label = $score >= 65 ? 'Strong' : ($score >= 45 ? 'Neutral' : 'Weak');

        return [
            'version' => 1,
            'label' => $label,
            'score' => round($score, 2),
            'advance_decline_ratio' => $ratio,
            'pct_above_50_dma' => null, // reserved for V2 constituent scan
            'pct_above_200_dma' => null,
            'new_highs' => null,
            'new_lows' => null,
            'new_highs_vs_lows' => null,
            'note' => 'V1 breadth uses index advance/decline persistence; constituent breadth is V2.',
        ];
    }

    /**
     * @param  list<float>  $closes
     * @return array<string, mixed>
     */
    protected function drawdownMetrics(array $closes, float $high52, float $ath, float $latest): array
    {
        $peak = $closes[0];
        $maxDd = 0.0;
        foreach ($closes as $c) {
            if ($c > $peak) {
                $peak = $c;
            }
            if ($peak > 0) {
                $dd = (($c - $peak) / $peak) * 100;
                if ($dd < $maxDd) {
                    $maxDd = $dd;
                }
            }
        }
        $curDd = $peak > 0 ? (($latest - $peak) / $peak) * 100 : 0;
        $dist52 = $high52 > 0 ? (($latest - $high52) / $high52) * 100 : null;
        $distAth = $ath > 0 ? (($latest - $ath) / $ath) * 100 : null;
        $recovery = ($maxDd < 0 && $curDd > $maxDd)
            ? round((1 - (abs($curDd) / abs($maxDd))) * 100, 2)
            : 100.0;

        return [
            'current_drawdown_pct' => round($curDd, 2),
            'maximum_drawdown_pct' => round($maxDd, 2),
            'recovery_pct' => $recovery,
            'distance_ath_pct' => $distAth !== null ? round($distAth, 2) : null,
            'distance_52w_high_pct' => $dist52 !== null ? round($dist52, 2) : null,
        ];
    }

    /**
     * @param  array<string, float|int>  $weights
     * @return array<string, mixed>
     */
    protected function buildSentiment(
        array $trend,
        array $momentum,
        array $breadth,
        array $risk,
        array $volatility,
        array $weights,
    ): array {
        $map = [
            'trend' => [(float) ($trend['score'] ?? 50), (float) ($weights['trend'] ?? 25)],
            'momentum' => [(float) ($momentum['score'] ?? 50), (float) ($weights['momentum'] ?? 20)],
            'breadth' => [(float) ($breadth['score'] ?? 50), (float) ($weights['breadth'] ?? 20)],
            'risk' => [(float) ($risk['score'] ?? 50), (float) ($weights['risk'] ?? 20)],
            'volatility' => [(float) ($volatility['score'] ?? 50), (float) ($weights['volatility'] ?? 15)],
        ];
        $earned = 0.0;
        $max = 0.0;
        $components = [];
        foreach ($map as $key => [$score, $weight]) {
            $contrib = ($score / 100.0) * $weight;
            $earned += $contrib;
            $max += $weight;
            $components[] = [
                'key' => $key,
                'display_name' => ucfirst($key),
                'score' => round($score, 2),
                'weight' => $weight,
                'contribution' => round($contrib, 2),
                'max_contribution' => $weight,
            ];
        }
        $total = $max > 0 ? round(($earned / $max) * 100, 2) : 50;
        $label = match (true) {
            $total >= 80 => 'Very Bullish',
            $total >= 65 => 'Bullish',
            $total >= 45 => 'Neutral',
            $total >= 30 => 'Bearish',
            default => 'Very Bearish',
        };

        return [
            'score' => $total,
            'label' => $label,
            'components' => $components,
        ];
    }

    /**
     * Deterministic phase classification.
     *
     * @return array{name:string,rule:string}
     */
    protected function classifyPhase(
        array $trend,
        array $momentum,
        array $drawdown,
        array $volatility,
        array $risk,
        float $sentiment,
    ): array {
        $dd = abs((float) ($drawdown['current_drawdown_pct'] ?? 0));
        $t = (float) ($trend['score'] ?? 50);
        $m = (float) ($momentum['score'] ?? 50);
        $volLabel = $volatility['label'] ?? 'Moderate';
        $riskLabel = $risk['label'] ?? 'Medium';

        if ($dd >= 20 && $volLabel === 'Extreme' && $m < 35) {
            return ['name' => self::PHASE_CAPITULATION, 'rule' => 'Drawdown≥20% + Extreme vol + weak momentum'];
        }
        if ($t >= 80 && $m >= 65 && $dd < 5 && $sentiment >= 75) {
            return ['name' => self::PHASE_STRONG_BULL, 'rule' => 'Strong trend + high momentum + shallow DD + sentiment≥75'];
        }
        if ($t >= 60 && $sentiment >= 60 && $dd < 8) {
            return ['name' => self::PHASE_BULL, 'rule' => 'Uptrend + sentiment≥60 + DD<8%'];
        }
        if ($t >= 55 && $dd >= 5 && $dd < 12 && $m < 55) {
            return ['name' => self::PHASE_PULLBACK, 'rule' => 'Uptrend intact with moderate DD and cooling momentum'];
        }
        if ($dd >= 12 && $dd < 20 && $t < 55) {
            return ['name' => self::PHASE_CORRECTION, 'rule' => 'DD 12–20% with weakened trend'];
        }
        if ($t <= 35 && $sentiment <= 40) {
            return ['name' => self::PHASE_BEAR, 'rule' => 'Downtrend + sentiment≤40'];
        }
        if ($t > 40 && $t < 60 && $m > 45 && $dd < 15 && $sentiment > 45 && $sentiment < 65) {
            return ['name' => self::PHASE_RECOVERY, 'rule' => 'Stabilising trend/momentum after stress'];
        }
        if ($riskLabel === 'Low' && $t >= 45 && $t <= 60) {
            return ['name' => self::PHASE_CONSOLIDATION, 'rule' => 'Sideways trend with contained risk'];
        }

        return ['name' => self::PHASE_CONSOLIDATION, 'rule' => 'Default consolidation'];
    }

    protected function regimeFromPhase(string $phase): string
    {
        return match ($phase) {
            self::PHASE_STRONG_BULL, self::PHASE_BULL, self::PHASE_RECOVERY => 'Bullish',
            self::PHASE_BEAR, self::PHASE_CAPITULATION, self::PHASE_CORRECTION => 'Bearish',
            default => 'Neutral',
        };
    }

    protected function allocationMultiplier(string $phase, float $sentiment, float $riskScore): float
    {
        $mult = match ($phase) {
            self::PHASE_STRONG_BULL => 1.15,
            self::PHASE_BULL => 1.05,
            self::PHASE_PULLBACK => 0.85,
            self::PHASE_CORRECTION => 0.6,
            self::PHASE_BEAR => 0.4,
            self::PHASE_CAPITULATION => 0.25,
            self::PHASE_RECOVERY => 0.9,
            default => 1.0,
        };
        if ($sentiment >= 80) {
            $mult *= 1.05;
        } elseif ($sentiment <= 30) {
            $mult *= 0.85;
        }
        if ($riskScore < 40) {
            $mult *= 0.8;
        }

        return round(max(0.2, min(1.25, $mult)), 3);
    }

    protected function newEntryAllowed(string $phase, string $riskLabel): bool
    {
        if (in_array($phase, [self::PHASE_BEAR, self::PHASE_CAPITULATION], true)) {
            return false;
        }
        if ($riskLabel === 'Extreme') {
            return false;
        }

        return true;
    }

    /**
     * @param  list<float>  $closes
     */
    protected function historicalVolatilityPct(array $closes, int $window = 20): ?float
    {
        if (count($closes) < $window + 1) {
            return null;
        }
        $rets = [];
        $slice = array_slice($closes, -($window + 1));
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i - 1] > 0) {
                $rets[] = log($slice[$i] / $slice[$i - 1]);
            }
        }
        if ($rets === []) {
            return null;
        }
        $mean = array_sum($rets) / count($rets);
        $var = 0.0;
        foreach ($rets as $r) {
            $var += ($r - $mean) ** 2;
        }
        $std = sqrt($var / max(1, count($rets) - 1));

        return round($std * sqrt(252) * 100, 2);
    }

    /**
     * @param  list<array{high:?float,low:?float,close:float}>  $ohlcv
     */
    protected function averageDailyRangePct(array $ohlcv, int $window = 14): ?float
    {
        $slice = array_slice($ohlcv, -$window);
        $vals = [];
        foreach ($slice as $bar) {
            if ($bar['high'] !== null && $bar['low'] !== null && $bar['close'] > 0) {
                $vals[] = (($bar['high'] - $bar['low']) / $bar['close']) * 100;
            }
        }

        return $vals !== [] ? round(array_sum($vals) / count($vals), 2) : null;
    }
}
