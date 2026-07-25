<?php

namespace App\Engines\Evaluation;

use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PortfolioLoggerService;
use App\Services\RelativeStrengthService;
use App\Services\Screener\TechnicalIndicatorService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Evaluation Engine — measurable factor facts only (SD-027).
 * Does not apply Strategy weights or produce recommendation decisions.
 */
class EvaluationEngine
{
    public function __construct(
        protected TechnicalIndicatorService $indicators,
        protected RelativeStrengthService $relativeStrength,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{run: EvaluationRun, results: list<EvaluationResult>}
     */
    public function run(PortfolioProfile $profile, ?DiscoveryRun $discoveryRun = null): array
    {
        $discoveryRun ??= DiscoveryRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $discoveryRun) {
            throw new \RuntimeException('No completed discovery run available for evaluation.');
        }

        $config = config('trading_os.evaluation', []);
        $run = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $discoveryRun->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $candidates = Candidate::query()
                ->where('discovery_run_id', $discoveryRun->id)
                ->with('security')
                ->get();

            $scored = [];
            foreach ($candidates as $candidate) {
                try {
                    $scored[] = $this->evaluateCandidate($candidate, $config);
                } catch (Throwable $candidateError) {
                    $scored[] = [
                        'candidate' => $candidate,
                        'score' => 0.0,
                        'confidence' => 0.0,
                        'evidence' => [
                            'skipped' => true,
                            'reason' => 'evaluation_error',
                            'error' => $candidateError->getMessage(),
                            'indicators' => [],
                        ],
                        'passed_rules' => [],
                        'failed_rules' => ['evaluation_error'],
                    ];
                    $this->logger->log('daily', 'EvaluationEngine', 'warning', 'Candidate evaluation failed', [
                        'candidate_id' => $candidate->id,
                        'security_id' => $candidate->security_id,
                        'error' => $candidateError->getMessage(),
                    ]);
                }
            }

            usort($scored, function ($a, $b) {
                // Informational ranking only (equal-weight mean of factor facts — not Strategy score).
                $cmp = $b['score'] <=> $a['score'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a['candidate']->id <=> $b['candidate']->id;
            });

            $results = [];
            DB::transaction(function () use ($run, $scored, &$results) {
                $rank = 1;
                foreach ($scored as $row) {
                    $results[] = EvaluationResult::query()->create([
                        'evaluation_run_id' => $run->id,
                        'candidate_id' => $row['candidate']->id,
                        'score' => $this->safeFloat($row['score']) ?? 0.0,
                        'confidence' => $this->safeFloat($row['confidence']) ?? 0.0,
                        'rank' => $rank++,
                        'evidence' => $this->jsonSafe($row['evidence']),
                        'passed_rules' => array_values($row['passed_rules'] ?? []),
                        'failed_rules' => array_values($row['failed_rules'] ?? []),
                        'created_at' => now(),
                    ]);
                }
            });

            $evaluated = count(array_filter(
                $scored,
                fn ($r) => ($r['evidence']['skipped'] ?? false) !== true,
            ));

            $run->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'stats_json' => [
                    'evaluated' => $evaluated,
                    'skipped' => max(0, $candidates->count() - $evaluated),
                    'results' => count($results),
                ],
            ])->save();

            $this->logger->log('daily', 'EvaluationEngine', 'info', 'Evaluation run completed', [
                'profile_id' => $profile->id,
                'run_id' => $run->id,
                'results' => count($results),
            ]);

            return ['run' => $run->fresh(), 'results' => $results];
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();

            $this->logger->log('daily', 'EvaluationEngine', 'error', 'Evaluation failed: '.$e->getMessage(), [
                'run_id' => $run->id,
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{candidate: Candidate, score: float, confidence: float, evidence: array, passed_rules: list<string>, failed_rules: list<string>}
     */
    protected function evaluateCandidate(Candidate $candidate, array $config): array
    {
        /** @var Stock|null $stock */
        $stock = $candidate->security;
        $minBars = (int) ($config['min_bars'] ?? 60);
        $bars = $this->loadBars($stock);
        $passed = [];
        $failed = [];

        if ($stock === null || count($bars) < $minBars) {
            $failed[] = 'insufficient_history';

            return [
                'candidate' => $candidate,
                'score' => 0.0,
                'confidence' => 0.0,
                'evidence' => [
                    'skipped' => true,
                    'reason' => 'insufficient_history',
                    'bar_count' => count($bars),
                    'indicators' => [],
                ],
                'passed_rules' => $passed,
                'failed_rules' => $failed,
            ];
        }

        $ti = $this->indicators->withBars($bars);
        $smaFastPeriod = (int) ($config['sma_fast'] ?? 20);
        $smaSlowPeriod = (int) ($config['sma_slow'] ?? 50);
        $rsiPeriod = (int) ($config['rsi_period'] ?? 14);
        $atrPeriod = (int) ($config['atr_period'] ?? 14);
        $volPeriod = (int) ($config['volume_sma_period'] ?? 20);

        $close = $ti->evaluate(['indicator' => 'close']);
        $smaFast = $ti->evaluate(['indicator' => 'sma', 'params' => ['period' => $smaFastPeriod]]);
        $smaSlow = $ti->evaluate(['indicator' => 'sma', 'params' => ['period' => $smaSlowPeriod]]);
        $rsi = $ti->evaluate(['indicator' => 'rsi', 'params' => ['period' => $rsiPeriod]]);
        $atr = $ti->evaluate(['indicator' => 'atr', 'params' => ['period' => $atrPeriod]]);
        $volumeRatio = $ti->evaluate(['indicator' => 'volume_ratio', 'params' => ['period' => $volPeriod]]);
        $priceVsSma = $ti->evaluate(['indicator' => 'price_vs_sma_pct', 'params' => ['period' => $smaFastPeriod]]);

        $rs = null;
        try {
            $rsValues = $this->relativeStrength->calculateForStock($stock);
            $rs = $rsValues['relative_strength_3m'] ?? null;
        } catch (Throwable) {
            $rs = null;
        }

        $weights = $config['weights'] ?? [];
        $trendScore = 0.0;
        if ($close !== null && $smaFast !== null && $smaSlow !== null) {
            if ($close > $smaFast && $smaFast > $smaSlow) {
                $trendScore = 100.0;
                $passed[] = 'uptrend_sma_stack';
            } elseif ($close > $smaFast) {
                $trendScore = 60.0;
                $passed[] = 'price_above_sma_fast';
            } else {
                $trendScore = 20.0;
                $failed[] = 'price_below_sma_fast';
            }
        } else {
            $failed[] = 'sma_unavailable';
        }

        $momentumScore = 50.0;
        if ($rsi !== null) {
            if ($rsi >= 45 && $rsi <= 70) {
                $momentumScore = 100.0;
                $passed[] = 'rsi_healthy';
            } elseif ($rsi > 70) {
                $momentumScore = 55.0;
                $failed[] = 'rsi_overbought';
            } elseif ($rsi < 30) {
                $momentumScore = 35.0;
                $failed[] = 'rsi_oversold';
            } else {
                $momentumScore = 50.0;
            }
        } else {
            $failed[] = 'rsi_unavailable';
        }

        $rsScore = 50.0;
        if ($rs !== null) {
            if ($rs >= 1.05) {
                $rsScore = 100.0;
                $passed[] = 'rs_outperform';
            } elseif ($rs >= 1.0) {
                $rsScore = 70.0;
                $passed[] = 'rs_inline';
            } else {
                $rsScore = 30.0;
                $failed[] = 'rs_underperform';
            }
        } else {
            $failed[] = 'rs_unavailable';
        }

        $volumeScore = 50.0;
        if ($volumeRatio !== null) {
            if ($volumeRatio >= 1.2) {
                $volumeScore = 100.0;
                $passed[] = 'volume_expansion';
            } elseif ($volumeRatio >= 0.8) {
                $volumeScore = 60.0;
            } else {
                $volumeScore = 30.0;
                $failed[] = 'volume_weak';
            }
        }

        $patternBonus = 0.0;
        $patterns = $candidate->evidence['patterns'] ?? [];
        $patternCount = is_array($patterns) ? count($patterns) : 0;
        if ($patternCount > 0) {
            $patternBonus = min(100.0, 40.0 + ($patternCount * 20.0));
            $passed[] = 'pattern_present';
        } else {
            $failed[] = 'no_pattern';
        }

        $atrPct = ($close && $atr && $close > 0) ? round(($atr / $close) * 100, 4) : null;
        // Risk fact: higher ATR% → higher risk score (0–100). Strategy may invert/weight.
        $riskScore = 50.0;
        if ($atrPct !== null) {
            $riskScore = round(max(0.0, min(100.0, $atrPct * 10.0)), 4);
            if ($riskScore <= 20) {
                $passed[] = 'risk_contained';
            } elseif ($riskScore >= 40) {
                $failed[] = 'risk_elevated';
            }
        } else {
            $failed[] = 'atr_unavailable';
        }

        $factorScores = [
            'relative_strength' => $this->safeFloat($rsScore) ?? 0.0,
            'momentum_score' => $this->safeFloat($momentumScore) ?? 0.0,
            'trend_score' => $this->safeFloat($trendScore) ?? 0.0,
            'breakout_score' => $this->safeFloat($patternBonus) ?? 0.0,
            'volume_score' => $this->safeFloat($volumeScore) ?? 0.0,
            // Neutral stubs until dedicated market/sector models ship (SD-028 catalogue).
            'market_regime' => 50.0,
            'sector_strength' => 50.0,
            'risk_score' => $this->safeFloat($riskScore) ?? 0.0,
            // Legacy aliases for older Strategy versions / UI
            'momentum' => $this->safeFloat($momentumScore) ?? 0.0,
            'trend' => $this->safeFloat($trendScore) ?? 0.0,
            'pattern_bonus' => $this->safeFloat($patternBonus) ?? 0.0,
            'volume' => $this->safeFloat($volumeScore) ?? 0.0,
            'risk' => $this->safeFloat($riskScore) ?? 0.0,
        ];

        // Equal-weight mean of catalogue scores for list ranking only (not Strategy score).
        $catalogueKeys = [
            'relative_strength', 'momentum_score', 'trend_score', 'breakout_score',
            'volume_score', 'market_regime', 'sector_strength', 'risk_score',
        ];
        $present = [];
        foreach ($catalogueKeys as $k) {
            if (isset($factorScores[$k]) && $factorScores[$k] !== null) {
                $present[] = $factorScores[$k];
            }
        }
        $score = $present !== []
            ? round(array_sum($present) / count($present), 4)
            : 0.0;
        $confidence = round(min(1.0, (count($passed) / max(1, count($passed) + count($failed)))), 4);

        return [
            'candidate' => $candidate,
            'score' => $score,
            'confidence' => $confidence,
            'evidence' => [
                'skipped' => false,
                'scoring_mode' => 'supported_indicator_facts',
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
                'discovery' => is_array($candidate->evidence) ? $candidate->evidence : [],
                'indicator_scores' => array_intersect_key($factorScores, array_flip($catalogueKeys)),
                'factor_scores' => $factorScores,
                'component_scores' => $factorScores,
            ],
            'passed_rules' => $passed,
            'failed_rules' => $failed,
        ];
    }

    /**
     * @return list<array{open:?float,high:?float,low:?float,close:float,volume:?float}>
     */
    protected function loadBars(?Stock $stock): array
    {
        if ($stock === null) {
            return [];
        }

        // Cap history for shared-hosting memory/time; ~18 months of sessions is enough for SMA50/RS.
        $limit = 400;
        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
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

    /**
     * JSON cannot encode NAN/INF; those crash Eloquent JSON casts and API responses.
     */
    protected function safeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        if (! is_finite($f)) {
            return null;
        }

        return $f;
    }

    protected function jsonSafe(mixed $value): mixed
    {
        if (is_float($value) || is_int($value)) {
            return $this->safeFloat($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->jsonSafe($v);
        }

        return $out;
    }

    /**
     * @return list<EvaluationResult>
     */
    public function listResults(?int $evaluationRunId = null, ?PortfolioProfile $profile = null): array
    {
        $query = EvaluationResult::query()->with(['candidate.security', 'evaluationRun']);

        if ($evaluationRunId) {
            $query->where('evaluation_run_id', $evaluationRunId);
        } elseif ($profile) {
            $latest = EvaluationRun::query()
                ->where('profile_id', $profile->id)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->value('id');
            if (! $latest) {
                return [];
            }
            $query->where('evaluation_run_id', $latest);
        }

        return $query->orderBy('rank')->get()->all();
    }
}
