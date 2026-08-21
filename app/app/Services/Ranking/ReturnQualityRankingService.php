<?php

namespace App\Services\Ranking;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use Illuminate\Support\Collection;

/**
 * V3 return-quality ranking engine (isolated, read-only).
 *
 * Implements:
 *  - OD-03  corpus: backtests only, latest completed run per strategy version
 *  - DEP-FIT-BAND-10  10-point fit bands with adaptive sparsity (max 2 merges, min-n 15→12→10)
 *  - OD-04  symmetric 7%/7% trimmed mean
 *  - DEP-TRIM-K  k = floor(0.07×n + 0.5), exact .5 upward
 *  - OD-18  CAGR/annualized as ranking evidence only when holding_days ≥ 30
 *  - §4.2.2 ranking statistical confidence diagnostic
 *
 * Does NOT allocate capital, mutate recommendations, or modify holdings/cash.
 */
class ReturnQualityRankingService
{
    public const BAND_WIDTH = 10;

    public const MAX_SCORE = 100;

    public const NORMAL_MIN_N = 15;

    public const REDUCED_MIN_N_STEP1 = 12;

    public const REDUCED_MIN_N_STEP2 = 10;

    public const MAX_NEIGHBOR_MERGES = 2;

    public const OD18_MIN_HOLDING_DAYS = 30;

    // ──────────────────────────────────────────────
    //  Public entry point
    // ──────────────────────────────────────────────

    /**
     * Compute return-quality ranking for all eligible fit bands of a strategy version.
     *
     * @return array{
     *     computable: bool,
     *     strategy_version_id: int,
     *     authoritative_run_id: int|null,
     *     corpus_size: int,
     *     bands: list<array>,
     *     reason: string|null
     * }
     */
    public function rankForStrategyVersion(int $strategyVersionId): array
    {
        $run = $this->selectAuthoritativeRun($strategyVersionId);

        if ($run === null) {
            return $this->unavailable($strategyVersionId, null, 0, 'No completed backtest run for this strategy version.');
        }

        $corpus = $this->selectCorpus($run);

        if ($corpus->isEmpty()) {
            return $this->unavailable($strategyVersionId, $run->id, 0, 'Authoritative backtest run has no closed trades.');
        }

        return $this->rankFromCorpus($strategyVersionId, $run->id, $corpus);
    }

    /**
     * Compute ranking from a pre-built corpus (testable without DB).
     *
     * @param  Collection<int, BacktestTrade>  $corpus
     */
    public function rankFromCorpus(int $strategyVersionId, ?int $runId, Collection $corpus): array
    {
        if ($corpus->isEmpty()) {
            return $this->unavailable($strategyVersionId, $runId, 0, 'Corpus is empty.');
        }

        $normalBands = $this->buildNormalBands($corpus);
        $resolvedBands = $this->resolveAllBands($normalBands);

        $anyComputable = false;
        foreach ($resolvedBands as $band) {
            if ($band['eligible']) {
                $anyComputable = true;
                break;
            }
        }

        return [
            'computable' => $anyComputable,
            'strategy_version_id' => $strategyVersionId,
            'authoritative_run_id' => $runId,
            'corpus_size' => $corpus->count(),
            'bands' => $resolvedBands,
            'reason' => $anyComputable ? null : 'No fit band reached the minimum observation threshold after adaptive sparsity.',
        ];
    }

    /**
     * Compute return-quality for a single fit score against a strategy version's corpus.
     *
     * @return array{
     *     computable: bool,
     *     fit_score: float,
     *     band_key: string,
     *     return_quality: float|null,
     *     confidence: float|null,
     *     diagnostic: array
     * }
     */
    public function rankForFitScore(int $strategyVersionId, float $fitScore): array
    {
        $full = $this->rankForStrategyVersion($strategyVersionId);

        if (! $full['computable']) {
            return [
                'computable' => false,
                'fit_score' => $fitScore,
                'band_key' => $this->bandKeyForScore($fitScore),
                'return_quality' => null,
                'confidence' => null,
                'diagnostic' => ['reason' => $full['reason']],
            ];
        }

        $targetBandKey = $this->bandKeyForScore($fitScore);

        foreach ($full['bands'] as $band) {
            if ($band['eligible'] && $this->scoreInBandSpan($fitScore, $band)) {
                return [
                    'computable' => true,
                    'fit_score' => $fitScore,
                    'band_key' => $band['band_key'],
                    'return_quality' => $band['return_quality'],
                    'confidence' => $band['confidence'],
                    'diagnostic' => $band,
                ];
            }
        }

        return [
            'computable' => false,
            'fit_score' => $fitScore,
            'band_key' => $targetBandKey,
            'return_quality' => null,
            'confidence' => null,
            'diagnostic' => ['reason' => 'Fit band for this score is not statistically eligible.'],
        ];
    }

    // ──────────────────────────────────────────────
    //  Stage 1: Corpus selection (OD-03 / §4.2.1)
    // ──────────────────────────────────────────────

    /**
     * Latest completed backtest run for a strategy version.
     */
    public function selectAuthoritativeRun(int $strategyVersionId): ?BacktestRun
    {
        return BacktestRun::query()
            ->where('strategy_version_id', $strategyVersionId)
            ->where('status', BacktestRun::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Closed trades from the authoritative run with a non-null entry_score.
     *
     * @return Collection<int, BacktestTrade>
     */
    public function selectCorpus(BacktestRun $run): Collection
    {
        return BacktestTrade::query()
            ->where('backtest_run_id', $run->id)
            ->where('is_open', false)
            ->whereNotNull('entry_score')
            ->whereNotNull('return_pct')
            ->orderBy('id')
            ->get();
    }

    // ──────────────────────────────────────────────
    //  Stage 2: Fit-band construction (DEP-FIT-BAND-10)
    // ──────────────────────────────────────────────

    /**
     * Assign a normal 10-point band key for a given score.
     * Bands: [0,10), [10,20), …, [90,100].
     * The last band is inclusive of 100.
     */
    public function bandKeyForScore(float $score): string
    {
        $lower = (int) (floor($score / self::BAND_WIDTH) * self::BAND_WIDTH);

        if ($lower >= self::MAX_SCORE) {
            $lower = self::MAX_SCORE - self::BAND_WIDTH;
        }

        $upper = $lower + self::BAND_WIDTH;

        if ($upper >= self::MAX_SCORE) {
            return "[{$lower},{$upper}]";
        }

        return "[{$lower},{$upper})";
    }

    /**
     * Build initial groups of observations per normal 10-point band.
     *
     * @param  Collection<int, BacktestTrade>  $corpus
     * @return array<string, list<BacktestTrade>>
     */
    public function buildNormalBands(Collection $corpus): array
    {
        $bands = [];
        foreach ($corpus as $trade) {
            $key = $this->bandKeyForScore((float) $trade->entry_score);
            $bands[$key][] = $trade;
        }

        ksort($bands);

        return $bands;
    }

    // ──────────────────────────────────────────────
    //  Stage 3: Adaptive sparsity (DEP-FIT-BAND-10)
    // ──────────────────────────────────────────────

    /**
     * Resolve all bands: attempt sparsity handling per DEP-FIT-BAND-10.
     *
     * @param  array<string, list<BacktestTrade>>  $normalBands
     * @return list<array>
     */
    public function resolveAllBands(array $normalBands): array
    {
        $allBandKeys = $this->allBandKeys();
        $results = [];

        foreach ($allBandKeys as $bandKey) {
            $observations = $normalBands[$bandKey] ?? [];
            $result = $this->resolveBand($bandKey, $observations, $normalBands);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Resolve a single band: merge neighbors if sparse, reduce min-n.
     *
     * @param  list<BacktestTrade>  $observations
     * @param  array<string, list<BacktestTrade>>  $allBands
     */
    private function resolveBand(string $bandKey, array $observations, array $allBands): array
    {
        $mergesPerformed = 0;
        $mergedKeys = [$bandKey];
        $pool = $observations;
        $n = count($pool);

        while ($n < self::NORMAL_MIN_N && $mergesPerformed < self::MAX_NEIGHBOR_MERGES) {
            $neighbor = $this->selectMergeNeighbor($mergedKeys, $allBands);
            if ($neighbor === null) {
                break;
            }
            $mergedKeys[] = $neighbor;
            $pool = array_merge($pool, $allBands[$neighbor] ?? []);
            $n = count($pool);
            $mergesPerformed++;
        }

        $effectiveMinN = $this->effectiveMinN($n, $mergesPerformed);
        $eligible = $n >= $effectiveMinN;
        $returnQuality = null;
        $trimDiagnostic = null;

        if ($eligible) {
            $returnValues = $this->extractReturnValues($pool);
            $trimDiagnostic = $this->symmetricTrimmedMean($returnValues);
            $returnQuality = $trimDiagnostic['mean'];
        }

        $confidence = $this->computeConfidence($n, $mergesPerformed, $effectiveMinN, $eligible);

        sort($mergedKeys);

        return [
            'band_key' => $bandKey,
            'merged_band_keys' => $mergedKeys,
            'original_n' => count($observations),
            'effective_n' => $n,
            'effective_min_n' => $effectiveMinN,
            'merges_performed' => $mergesPerformed,
            'eligible' => $eligible,
            'return_quality' => $returnQuality,
            'confidence' => $confidence,
            'trim_diagnostic' => $trimDiagnostic,
        ];
    }

    /**
     * Neighbor selection: prefer the adjacent band with MORE observations.
     * If equal, prefer the band closer to 50 (higher fit region preservation).
     * This is an engineering choice since the spec says "implementation chooses left or right neighbor deterministically".
     *
     * @param  list<string>  $currentKeys  Band keys already merged
     * @param  array<string, list<BacktestTrade>>  $allBands
     */
    private function selectMergeNeighbor(array $currentKeys, array $allBands): ?string
    {
        $allKeys = $this->allBandKeys();
        $currentIndices = [];
        foreach ($currentKeys as $k) {
            $idx = array_search($k, $allKeys, true);
            if ($idx !== false) {
                $currentIndices[] = $idx;
            }
        }

        if ($currentIndices === []) {
            return null;
        }

        $minIdx = min($currentIndices);
        $maxIdx = max($currentIndices);

        $leftIdx = $minIdx - 1;
        $rightIdx = $maxIdx + 1;

        $leftKey = $leftIdx >= 0 ? $allKeys[$leftIdx] : null;
        $rightKey = $rightIdx < count($allKeys) ? $allKeys[$rightIdx] : null;

        if ($leftKey !== null && in_array($leftKey, $currentKeys, true)) {
            $leftKey = null;
        }
        if ($rightKey !== null && in_array($rightKey, $currentKeys, true)) {
            $rightKey = null;
        }

        if ($leftKey === null && $rightKey === null) {
            return null;
        }
        if ($leftKey === null) {
            return $rightKey;
        }
        if ($rightKey === null) {
            return $leftKey;
        }

        $leftCount = count($allBands[$leftKey] ?? []);
        $rightCount = count($allBands[$rightKey] ?? []);

        if ($leftCount > $rightCount) {
            return $leftKey;
        }
        if ($rightCount > $leftCount) {
            return $rightKey;
        }

        return $rightKey;
    }

    /**
     * Determine effective minimum-n after DEP-FIT-BAND-10 sparsity.
     */
    private function effectiveMinN(int $n, int $mergesPerformed): int
    {
        if ($n >= self::NORMAL_MIN_N) {
            return self::NORMAL_MIN_N;
        }

        if ($n >= self::REDUCED_MIN_N_STEP1) {
            return self::REDUCED_MIN_N_STEP1;
        }

        if ($n >= self::REDUCED_MIN_N_STEP2) {
            return self::REDUCED_MIN_N_STEP2;
        }

        return self::REDUCED_MIN_N_STEP2;
    }

    // ──────────────────────────────────────────────
    //  Stage 4: Return metric extraction (OD-18)
    // ──────────────────────────────────────────────

    /**
     * Extract the ranking return value for each observation.
     *
     * Spec analysis (§4.5, §20, OD-18):
     *   - §4.5: "Prefer XIRR / annualized return", subject to OD-18
     *   - §20 row "No-horizon strategy, completed backtest holding":
     *     "Holding-period simple return AND XIRR / annualized return"
     *   - OD-18: annualized/CAGR/XIRR MAY be ranking evidence only when holding ≥ 30 days;
     *     < 30 days → MUST NOT contribute annualized return; simple return remains valid.
     *
     * Engineering choice: Use CAGR (annualized) when holding_days ≥ 30 and CAGR is non-null;
     * fall back to return_pct (simple return) otherwise. This follows the spec's preference
     * for annualized return while respecting OD-18. Both metrics exist on persisted trades.
     *
     * @param  list<BacktestTrade>  $observations
     * @return list<float>
     */
    public function extractReturnValues(array $observations): array
    {
        $values = [];
        foreach ($observations as $trade) {
            $value = $this->rankingReturnForTrade($trade);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Select the return metric for a single trade.
     *
     * OD-18: CAGR only when holding_days ≥ 30 AND cagr is non-null.
     * Otherwise: return_pct (simple return). Always valid.
     */
    public function rankingReturnForTrade(BacktestTrade $trade): ?float
    {
        $holdingDays = (int) ($trade->holding_days ?? 0);
        $cagr = $trade->cagr;
        $returnPct = $trade->return_pct;

        if ($holdingDays >= self::OD18_MIN_HOLDING_DAYS && $cagr !== null && is_finite($cagr)) {
            return (float) $cagr;
        }

        if ($returnPct !== null && is_finite($returnPct)) {
            return (float) $returnPct;
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Stage 5 + 6: Trimming + aggregation (DEP-TRIM-K / OD-04)
    // ──────────────────────────────────────────────

    /**
     * Symmetric trimmed mean per OD-04 / DEP-TRIM-K.
     *
     * @param  list<float>  $values
     * @return array{mean: float|null, n: int, k: int, trimmed_lower: list<float>, trimmed_upper: list<float>, remaining: list<float>}
     */
    public function symmetricTrimmedMean(array $values): array
    {
        $n = count($values);

        if ($n === 0) {
            return [
                'mean' => null,
                'n' => 0,
                'k' => 0,
                'trimmed_lower' => [],
                'trimmed_upper' => [],
                'remaining' => [],
            ];
        }

        sort($values);

        $k = self::trimCount($n);

        $trimmedLower = array_slice($values, 0, $k);
        $trimmedUpper = $k > 0 ? array_slice($values, -$k) : [];
        $remaining = array_slice($values, $k, $n - 2 * $k);

        $mean = count($remaining) > 0
            ? array_sum($remaining) / count($remaining)
            : null;

        return [
            'mean' => $mean !== null ? round($mean, 6) : null,
            'n' => $n,
            'k' => $k,
            'trimmed_lower' => $trimmedLower,
            'trimmed_upper' => $trimmedUpper,
            'remaining' => $remaining,
        ];
    }

    /**
     * DEP-TRIM-K: k = floor(0.07 × n + 0.5).
     * Exact .5 rounds upward. Not banker's rounding.
     */
    public static function trimCount(int $n): int
    {
        return (int) floor(0.07 * $n + 0.5);
    }

    // ──────────────────────────────────────────────
    //  Stage 7: Confidence diagnostic (§4.2.2)
    // ──────────────────────────────────────────────

    /**
     * Ranking statistical confidence as a 0.0–1.0 diagnostic.
     * Decreases with merges and threshold reductions.
     * MUST NOT affect ranking, allocation, or eligibility.
     */
    private function computeConfidence(int $n, int $mergesPerformed, int $effectiveMinN, bool $eligible): float
    {
        if (! $eligible) {
            return 0.0;
        }

        $base = 1.0;

        if ($mergesPerformed >= 1) {
            $base -= 0.15;
        }
        if ($mergesPerformed >= 2) {
            $base -= 0.15;
        }

        if ($effectiveMinN === self::REDUCED_MIN_N_STEP1) {
            $base -= 0.10;
        } elseif ($effectiveMinN === self::REDUCED_MIN_N_STEP2) {
            $base -= 0.20;
        }

        $sizeFactor = min(1.0, $n / 50.0);
        $base *= (0.5 + 0.5 * $sizeFactor);

        return round(max(0.0, min(1.0, $base)), 4);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * All normal 10-point band keys in ascending order.
     *
     * @return list<string>
     */
    public function allBandKeys(): array
    {
        $keys = [];
        for ($lower = 0; $lower < self::MAX_SCORE; $lower += self::BAND_WIDTH) {
            $upper = $lower + self::BAND_WIDTH;
            $keys[] = $upper >= self::MAX_SCORE
                ? "[{$lower},{$upper}]"
                : "[{$lower},{$upper})";
        }

        return $keys;
    }

    private function scoreInBandSpan(float $score, array $band): bool
    {
        foreach ($band['merged_band_keys'] as $key) {
            if ($this->bandKeyForScore($score) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{computable: false, strategy_version_id: int, authoritative_run_id: int|null, corpus_size: int, bands: list<never>, reason: string}
     */
    private function unavailable(int $strategyVersionId, ?int $runId, int $corpusSize, string $reason): array
    {
        return [
            'computable' => false,
            'strategy_version_id' => $strategyVersionId,
            'authoritative_run_id' => $runId,
            'corpus_size' => $corpusSize,
            'bands' => [],
            'reason' => $reason,
        ];
    }
}
