<?php

namespace App\Engines\Evaluation;

use App\Engines\Market\MarketAnalysisEngine;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Repositories\Tos\DiscoveryCandidateRepository;
use App\Repositories\Tos\EvaluationResultRepository;
use App\Repositories\Tos\MarketDataRepository;
use App\Services\DataQualityGuardService;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\PortfolioLoggerService;
use App\Services\RelativeStrengthService;
use App\Services\Screener\TechnicalIndicatorService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Evaluation Engine — measurable factor facts only (SD-027).
 * Orchestrates run/candidate lifecycle; factor formulas live in EvaluationFactorRule modules (V4-FEAT-029).
 * Does not apply Strategy weights or produce recommendation decisions.
 */
class EvaluationEngine
{
    public function __construct(
        protected TechnicalIndicatorService $indicators,
        protected RelativeStrengthService $relativeStrength,
        protected PortfolioLoggerService $logger,
        protected DataQualityGuardService $dataQualityGuard,
        protected EvaluationParameterResolver $parameterResolver,
        protected MarketAnalysisEngine $marketAnalysis,
        protected MarketRegimeScoreMapper $regimeScores,
        protected EvaluationFactorRuleSet $factorRules,
        protected DiscoveryCandidateRepository $discoveryCandidates,
        protected EvaluationResultRepository $evaluationResults,
        protected MarketDataRepository $marketData,
        protected IndicatorRegistry $indicatorRegistry,
    ) {}

    /**
     * @param  array<string, mixed>|null  $evaluationConfig  Resolved EvaluationParameterResolver output; null = globals
     * @return array{run: EvaluationRun, results: list<EvaluationResult>}
     */
    public function run(PortfolioProfile $profile, ?DiscoveryRun $discoveryRun = null, ?array $evaluationConfig = null): array
    {
        $discoveryRun ??= $this->discoveryCandidates->latestCompleted($profile);

        if (! $discoveryRun) {
            throw new \RuntimeException('No completed discovery run available for evaluation.');
        }

        $config = $evaluationConfig ?? $this->parameterResolver->globals();
        $run = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $discoveryRun->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $candidates = $this->discoveryCandidates->forDiscoveryRun($discoveryRun->id);
            $blockedMap = $this->dataQualityGuard->blockedStockIdMap(
                $candidates->pluck('security_id')->map(fn ($id) => (int) $id)->all(),
            );

            $marketPayload = $this->marketAnalysis->latest();
            $marketRegime = is_string($marketPayload['market_regime'] ?? null)
                ? $marketPayload['market_regime']
                : 'Neutral';
            $marketRegimeScore = $this->regimeScores->score($marketRegime);

            $scored = [];
            foreach ($candidates as $candidate) {
                if (! empty($blockedMap[(int) $candidate->security_id])) {
                    $scored[] = [
                        'candidate' => $candidate,
                        'score' => 0.0,
                        'confidence' => 0.0,
                        'evidence' => [
                            'skipped' => true,
                            'reason' => 'data_quality_pending_review',
                            'indicators' => [],
                        ],
                        'passed_rules' => [],
                        'failed_rules' => ['data_quality_pending_review'],
                    ];

                    continue;
                }
                try {
                    $scored[] = $this->evaluateCandidate($candidate, $config, $marketRegime, $marketRegimeScore);
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
                    $this->logger->event('EvaluationEngine', 'evaluation.candidate_failed', 'warning', 'Candidate evaluation failed', [
                        'profile_id' => $profile->id,
                        'evaluation_run_id' => $run->id,
                        'candidate_id' => $candidate->id,
                        'security_id' => $candidate->security_id,
                        'exception' => $candidateError->getMessage(),
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
                    'evaluation_parameters' => $this->publicParameters($config),
                ],
            ])->save();

            $this->logger->event('EvaluationEngine', 'evaluation.completed', 'info', 'Evaluation run completed', [
                'profile_id' => $profile->id,
                'evaluation_run_id' => $run->id,
                'discovery_run_id' => $discoveryRun->id,
                'results' => count($results),
            ]);

            return ['run' => $run->fresh(), 'results' => $results];
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();

            $this->logger->event('EvaluationEngine', 'evaluation.failed', 'error', 'Evaluation failed', [
                'profile_id' => $profile->id,
                'evaluation_run_id' => $run->id,
                'discovery_run_id' => $discoveryRun->id,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{candidate: Candidate, score: float, confidence: float, evidence: array, passed_rules: list<string>, failed_rules: list<string>}
     */
    protected function evaluateCandidate(
        Candidate $candidate,
        array $config,
        string $marketRegime,
        float $marketRegimeScore,
    ): array {
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
        $rsError = null;
        try {
            $lookback = ! empty($config['use_lookback_days']) ? (int) $config['lookback_days'] : null;
            $benchmarkSymbol = isset($config['benchmark']) && is_string($config['benchmark'])
                ? $config['benchmark']
                : null;
            $rs = $this->relativeStrength->evaluationRelativeStrength($stock, $benchmarkSymbol, $lookback);
        } catch (Throwable $error) {
            $rs = null;
            $rsError = $error->getMessage();
        }

        $closeF = $this->safeFloat($close);
        $atrF = $this->safeFloat($atr);
        $atrPct = ($closeF && $atrF && $closeF > 0) ? round(($atrF / $closeF) * 100, 4) : null;
        $patterns = $candidate->evidence['patterns'] ?? [];
        $patternCount = is_array($patterns) ? count($patterns) : 0;

        $context = new EvaluationFactorContext(
            close: $closeF,
            smaFast: $this->safeFloat($smaFast),
            smaSlow: $this->safeFloat($smaSlow),
            rsi: $this->safeFloat($rsi),
            atr: $atrF,
            atrPct: $this->safeFloat($atrPct),
            volumeRatio: $this->safeFloat($volumeRatio),
            priceVsSma: $this->safeFloat($priceVsSma),
            relativeStrength: $this->safeFloat($rs),
            marketRegime: $marketRegime,
            marketRegimeScore: $marketRegimeScore,
            patternCount: $patternCount,
        );

        [$factorScores, $passed, $failed] = $this->applyFactorRules($context);

        $catalogueKeys = EvaluationFactorRuleSet::CATALOGUE_KEYS;
        $present = [];
        foreach ($catalogueKeys as $k) {
            if (isset($factorScores[$k]) && $factorScores[$k] !== null) {
                $present[] = $factorScores[$k];
            }
        }
        foreach ($factorScores as $k => $value) {
            if (! in_array($k, $catalogueKeys, true) && ! $this->isLegacyAlias($k) && $value !== null) {
                $present[] = $value;
            }
        }
        $score = $present !== []
            ? round(array_sum($present) / count($present), 4)
            : 0.0;
        $confidence = round(min(1.0, (count($passed) / max(1, count($passed) + count($failed)))), 4);
        $asOf = $bars[array_key_last($bars)]['as_of'] ?? null;
        $indicatorEvidence = $this->indicatorEvidence(
            $config,
            $asOf,
            [
                'close' => ['id' => 'close', 'parameters' => [], 'value' => $closeF],
                'sma_fast' => ['id' => 'sma', 'parameters' => ['period' => $smaFastPeriod], 'value' => $this->safeFloat($smaFast)],
                'sma_slow' => ['id' => 'sma', 'parameters' => ['period' => $smaSlowPeriod], 'value' => $this->safeFloat($smaSlow)],
                'rsi' => ['id' => 'rsi', 'parameters' => ['period' => $rsiPeriod], 'value' => $this->safeFloat($rsi)],
                'atr' => ['id' => 'atr', 'parameters' => ['period' => $atrPeriod], 'value' => $atrF],
                'volume_ratio' => ['id' => 'volume_ratio', 'parameters' => ['period' => $volPeriod], 'value' => $this->safeFloat($volumeRatio)],
                'price_vs_sma_pct' => ['id' => 'price_vs_sma_pct', 'parameters' => ['period' => $smaFastPeriod], 'value' => $this->safeFloat($priceVsSma)],
                'relative_strength_3m' => [
                    'id' => 'relative_strength_3m',
                    'parameters' => [
                        'lookback_days' => ! empty($config['use_lookback_days']) ? (int) $config['lookback_days'] : null,
                        'benchmark' => $config['benchmark'] ?? null,
                    ],
                    'value' => $this->safeFloat($rs),
                    'error' => $rsError,
                ],
            ],
            count($bars),
        );
        foreach (array_intersect_key($factorScores, array_flip($catalogueKeys)) as $id => $value) {
            $components = $this->factorComponents($id, $context);
            $indicatorEvidence[$id] = $this->indicatorEvidenceRow(
                $id,
                $id,
                $config['indicator_versions'][$id] ?? null,
                $this->factorParameters($id, $config),
                $this->safeFloat($value),
                $asOf,
                ['source' => 'evaluation_factor_rules', 'inputs' => $components],
                unavailableReason: $this->factorUnavailableReason($id, $components),
            );
        }

        return [
            'candidate' => $candidate,
            'score' => $score,
            'confidence' => $confidence,
            'evidence' => [
                'skipped' => false,
                'scoring_mode' => 'supported_indicator_facts',
                'evaluation_parameters' => $this->publicParameters($config),
                'market_regime' => $marketRegime,
                'market_regime_score' => $marketRegimeScore,
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
                'indicator_evidence' => $indicatorEvidence,
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
     * @return list<array{as_of:?string,open:?float,high:?float,low:?float,close:float,volume:?float}>
     */
    protected function loadBars(?Stock $stock): array
    {
        if ($stock === null) {
            return [];
        }

        // Cap history for shared-hosting memory/time; ~18 months of sessions is enough for SMA50/RS.
        $rows = $this->marketData->recentClosePriceRows($stock->id, 400);

        $bars = [];
        foreach ($rows->reverse()->values() as $row) {
            $close = $this->safeFloat($row->close_price);
            if ($close === null) {
                continue;
            }
            $bars[] = [
                'as_of' => $row->price_date instanceof \DateTimeInterface
                    ? $row->price_date->format('Y-m-d')
                    : (isset($row->price_date) ? (string) $row->price_date : null),
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
     * @return array{0: array<string, float>, 1: list<string>, 2: list<string>}
     */
    protected function applyFactorRules(EvaluationFactorContext $context): array
    {
        $byKey = [];
        $aliases = [];
        $passed = [];
        $failed = [];
        foreach ($this->factorRules->all() as $rule) {
            $result = $rule->evaluate($context);
            $byKey[$result->key] = $this->safeFloat($result->score) ?? 0.0;
            foreach ($result->aliases as $alias => $value) {
                $aliases[(string) $alias] = $this->safeFloat($value) ?? 0.0;
            }
            $passed = array_merge($passed, $result->passed);
            $failed = array_merge($failed, $result->failed);
        }

        $factorScores = [];
        foreach (EvaluationFactorRuleSet::CATALOGUE_KEYS as $key) {
            if (array_key_exists($key, $byKey)) {
                $factorScores[$key] = $byKey[$key];
            }
        }
        foreach ($byKey as $key => $value) {
            if (! array_key_exists($key, $factorScores)) {
                $factorScores[$key] = $value;
            }
        }
        foreach (['momentum', 'trend', 'pattern_bonus', 'volume', 'risk'] as $alias) {
            if (array_key_exists($alias, $aliases)) {
                $factorScores[$alias] = $aliases[$alias];
            }
        }
        foreach ($aliases as $alias => $value) {
            if (! array_key_exists($alias, $factorScores)) {
                $factorScores[$alias] = $value;
            }
        }

        return [$factorScores, $passed, $failed];
    }

    protected function isLegacyAlias(string $key): bool
    {
        return in_array($key, ['momentum', 'trend', 'pattern_bonus', 'volume', 'risk'], true);
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
        return $this->evaluationResults->listResults($evaluationRunId, $profile);
    }

    /**
     * @return list<EvaluationRun>
     */
    public function listRuns(PortfolioProfile $profile, int $limit = 20): array
    {
        return $this->evaluationResults->listRuns($profile, $limit);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function publicParameters(array $config): array
    {
        return [
            'rsi_period' => (int) ($config['rsi_period'] ?? 14),
            'sma_fast' => (int) ($config['sma_fast'] ?? 20),
            'sma_slow' => (int) ($config['sma_slow'] ?? 50),
            'atr_period' => (int) ($config['atr_period'] ?? 14),
            'volume_sma_period' => (int) ($config['volume_sma_period'] ?? 20),
            'lookback_days' => ! empty($config['use_lookback_days']) ? (int) $config['lookback_days'] : null,
            'benchmark' => $config['benchmark'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, array<string, mixed>>  $evaluations
     * @return array<string, array<string, mixed>>
     */
    protected function indicatorEvidence(array $config, mixed $asOf, array $evaluations, int $barCount): array
    {
        $out = [];
        foreach ($evaluations as $key => $evaluation) {
            $out[$key] = $this->indicatorEvidenceRow(
                (string) $key,
                (string) $evaluation['id'],
                $config['indicator_versions'][$evaluation['id']] ?? null,
                is_array($evaluation['parameters'] ?? null) ? $evaluation['parameters'] : [],
                $this->safeFloat($evaluation['value'] ?? null),
                $asOf,
                ['source' => 'stock_prices', 'bar_count' => $barCount],
                isset($evaluation['error']) ? (string) $evaluation['error'] : null,
            );
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function indicatorEvidenceRow(
        string $evidenceKey,
        string $id,
        ?string $pinnedVersion,
        array $parameters,
        ?float $value,
        mixed $asOf,
        array $components,
        ?string $error = null,
        ?string $unavailableReason = null,
    ): array {
        $definition = $pinnedVersion !== null
            ? $this->indicatorRegistry->findVersion($id, $pinnedVersion)
            : $this->indicatorRegistry->find($id);
        $state = $error !== null ? 'error' : ($value === null || $unavailableReason !== null ? 'unavailable' : 'available');
        if ($state !== 'available') {
            $value = null;
        }

        return [
            'evidence_key' => $evidenceKey,
            'indicator_id' => $id,
            'indicator_version' => $definition?->version,
            'effective_parameters' => $parameters,
            'state' => $state,
            'value' => $value,
            'as_of' => is_string($asOf) && $asOf !== '' ? $asOf : null,
            'components' => $components,
            'reason' => $state === 'unavailable'
                ? ($unavailableReason ?? 'not_computable')
                : ($state === 'error' ? 'calculation_error' : null),
            'error' => $error,
        ];
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    protected function factorParameters(string $id, array $config): array
    {
        return match ($id) {
            'momentum_score' => ['rsi_period' => (int) ($config['rsi_period'] ?? 14)],
            'trend_score' => ['sma_fast' => (int) ($config['sma_fast'] ?? 20), 'sma_slow' => (int) ($config['sma_slow'] ?? 50)],
            'volume_score' => ['volume_sma_period' => (int) ($config['volume_sma_period'] ?? 20)],
            'relative_strength' => [
                'lookback_days' => ! empty($config['use_lookback_days']) ? (int) $config['lookback_days'] : null,
                'benchmark' => $config['benchmark'] ?? null,
            ],
            'risk_score' => ['atr_period' => (int) ($config['atr_period'] ?? 14)],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    protected function factorComponents(string $id, EvaluationFactorContext $context): array
    {
        return match ($id) {
            'momentum_score' => ['rsi' => $context->rsi],
            'trend_score' => ['close' => $context->close, 'sma_fast' => $context->smaFast, 'sma_slow' => $context->smaSlow],
            'breakout_score' => ['price_vs_sma_pct' => $context->priceVsSma, 'pattern_count' => $context->patternCount],
            'volume_score' => ['volume_ratio' => $context->volumeRatio],
            'relative_strength' => ['relative_strength_3m' => $context->relativeStrength],
            'market_regime' => ['market_regime' => $context->marketRegime],
            'sector_strength' => ['state' => 'neutral_stub'],
            'risk_score' => ['atr_pct' => $context->atrPct],
            default => [],
        };
    }

    /** @param array<string, mixed> $components */
    protected function factorUnavailableReason(string $id, array $components): ?string
    {
        if ($id === 'sector_strength') {
            return 'registered_stub';
        }
        if ($components === []) {
            return null;
        }
        foreach ($components as $key => $value) {
            if ($key !== 'pattern_count' && $value === null) {
                return 'component_unavailable';
            }
        }

        return null;
    }
}
