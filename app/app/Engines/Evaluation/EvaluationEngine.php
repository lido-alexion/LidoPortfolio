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
 * Evaluation Engine — indicators, rules, scoring, ranking (deterministic).
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
                $scored[] = $this->evaluateCandidate($candidate, $config);
            }

            usort($scored, function ($a, $b) {
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
                        'score' => $row['score'],
                        'confidence' => $row['confidence'],
                        'rank' => $rank++,
                        'evidence' => $row['evidence'],
                        'passed_rules' => $row['passed_rules'],
                        'failed_rules' => $row['failed_rules'],
                        'created_at' => now(),
                    ]);
                }
            });

            $run->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'stats_json' => [
                    'evaluated' => count($results),
                    'skipped' => $candidates->count() - count(array_filter($scored, fn ($r) => ($r['evidence']['skipped'] ?? false) === false)),
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
        $patternCount = count($candidate->evidence['patterns'] ?? []);
        if ($patternCount > 0) {
            $patternBonus = min(100.0, 40.0 + ($patternCount * 20.0));
            $passed[] = 'pattern_present';
        } else {
            $failed[] = 'no_pattern';
        }

        $score =
            ($trendScore * (float) ($weights['trend'] ?? 0.3)) +
            ($momentumScore * (float) ($weights['momentum'] ?? 0.25)) +
            ($rsScore * (float) ($weights['relative_strength'] ?? 0.25)) +
            ($volumeScore * (float) ($weights['volume'] ?? 0.1)) +
            ($patternBonus * (float) ($weights['pattern_bonus'] ?? 0.1));

        $score = round(max(0.0, min(100.0, $score)), 4);
        $confidence = round(min(1.0, (count($passed) / max(1, count($passed) + count($failed)))), 4);

        $atrPct = ($close && $atr && $close > 0) ? round(($atr / $close) * 100, 4) : null;

        return [
            'candidate' => $candidate,
            'score' => $score,
            'confidence' => $confidence,
            'evidence' => [
                'skipped' => false,
                'indicators' => [
                    'close' => $close,
                    'sma_fast' => $smaFast,
                    'sma_slow' => $smaSlow,
                    'rsi' => $rsi,
                    'atr' => $atr,
                    'atr_pct' => $atrPct,
                    'volume_ratio' => $volumeRatio,
                    'price_vs_sma_pct' => $priceVsSma,
                    'relative_strength_3m' => $rs,
                ],
                'discovery' => $candidate->evidence,
                'component_scores' => [
                    'trend' => $trendScore,
                    'momentum' => $momentumScore,
                    'relative_strength' => $rsScore,
                    'volume' => $volumeScore,
                    'pattern_bonus' => $patternBonus,
                ],
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

        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderBy('price_date')
            ->get(['open_price', 'high_price', 'low_price', 'close_price', 'volume']);

        $bars = [];
        foreach ($rows as $row) {
            $bars[] = [
                'open' => $row->open_price !== null ? (float) $row->open_price : null,
                'high' => $row->high_price !== null ? (float) $row->high_price : null,
                'low' => $row->low_price !== null ? (float) $row->low_price : null,
                'close' => (float) $row->close_price,
                'volume' => $row->volume !== null ? (float) $row->volume : null,
            ];
        }

        return $bars;
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
