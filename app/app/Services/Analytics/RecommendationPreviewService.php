<?php

namespace App\Services\Analytics;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;

/**
 * SD-031 — Recommendation Preview for Watchlist research (active Strategy).
 */
class RecommendationPreviewService
{
    public function __construct(
        protected StrategyConfigurationService $strategies,
        protected StrategyEligibilityService $eligibility,
        protected EvaluationProfileService $evaluationProfiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStock(PortfolioProfile $profile, Stock $stock): array
    {
        $existing = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('security_id', $stock->id)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
                TradingRecommendation::STATUS_PUBLISHED,
                TradingRecommendation::STATUS_DEFERRED,
            ])
            ->orderByDesc('id')
            ->first();

        $strategy = $this->strategies->getActiveStrategy($profile);
        $profileEval = $this->evaluationProfiles->forStock($profile, $stock);
        $eligibility = $this->eligibility->resolve($profile, $strategy['config'] ?? []);
        $screenerExplain = $this->eligibility->explainForSecurity($eligibility, (int) $stock->id);

        if ($existing) {
            $evidence = is_array($existing->evidence) ? $existing->evidence : [];

            return [
                'owner' => 'recommendation_engine',
                'available' => true,
                'source' => 'persisted',
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'recommendation' => $existing->recommendation_type,
                'recommendation_score' => $existing->strategy_score !== null
                    ? round((float) $existing->strategy_score, 2)
                    : null,
                'confidence' => $existing->confidence !== null ? round((float) $existing->confidence, 2) : null,
                'strategy' => [
                    'name' => $strategy['name'] ?? null,
                    'version_label' => $strategy['version_label'] ?? null,
                    'is_factory' => $strategy['is_factory'] ?? false,
                ],
                'eligibility_sources' => $evidence['eligibility']['screeners']
                    ?? $screenerExplain,
                'suggested_allocation_pct' => $existing->suggested_allocation_pct,
                'reason_summary' => $existing->reasoning,
                'status' => $existing->status,
                'recommendation_id' => $existing->id,
            ];
        }

        // Preview from Evaluation Profile + Strategy scoring when no open recommendation.
        $score = null;
        $breakdown = [];
        if ($profileEval['available'] ?? false) {
            $factorScores = [
                'relative_strength' => $profileEval['relative_strength'] ?? null,
                'momentum_score' => $profileEval['momentum_score'] ?? null,
                'trend_score' => $profileEval['trend_score'] ?? null,
                'breakout_score' => $profileEval['breakout_score'] ?? null,
                'volume_score' => $profileEval['volume_score'] ?? null,
                'market_regime' => $profileEval['market_alignment'] ?? null,
                'sector_strength' => $profileEval['sector_strength'] ?? null,
                'risk_score' => $profileEval['risk_score'] ?? null,
            ];
            $scored = $this->strategies->score($factorScores, $strategy['config'] ?? []);
            $score = $scored['overall_score'];
            $breakdown = $scored['breakdown'];
        }

        $thresholds = $strategy['thresholds'] ?? [];
        $openMin = (float) ($thresholds['open_position'] ?? 85);
        $watchMin = (float) ($thresholds['watch'] ?? 60);
        $previewAction = 'WATCH';
        if ($score !== null && $score >= $openMin) {
            $previewAction = 'OPEN_POSITION';
        } elseif ($score !== null && $score < $watchMin) {
            $previewAction = 'HOLD_OR_AVOID';
        }

        $allocPct = $score !== null
            ? $this->strategies->allocationPctForScore((float) $score, $strategy['config'] ?? [])
            : null;

        $enabledSources = array_values(array_filter(
            $strategy['eligibility_sources'] ?? [],
            fn ($s) => ($s['enabled'] ?? true)
        ));

        return [
            'owner' => 'recommendation_engine',
            'available' => $score !== null,
            'source' => 'preview',
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'recommendation' => $previewAction,
            'recommendation_score' => $score !== null ? round((float) $score, 2) : null,
            'confidence' => $profileEval['confidence'] ?? null,
            'strategy' => [
                'name' => $strategy['name'] ?? null,
                'version_label' => $strategy['version_label'] ?? null,
                'is_factory' => $strategy['is_factory'] ?? false,
            ],
            'eligibility_sources' => array_map(fn ($s) => [
                'screener_id' => $s['screener_id'] ?? null,
                'name' => $s['screener_name'] ?? '',
                'status' => collect($screenerExplain)->firstWhere('screener_id', $s['screener_id'])['status'] ?? 'UNKNOWN',
            ], $enabledSources),
            'suggested_allocation_pct' => $allocPct,
            'reason_summary' => $score !== null
                ? 'Preview from Evaluation Profile scored by active Strategy (not yet a generated recommendation).'
                : 'Run Evaluation to unlock a recommendation preview.',
            'scoring_breakdown' => $breakdown,
            'status' => null,
            'recommendation_id' => null,
        ];
    }
}
