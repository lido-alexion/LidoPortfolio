<?php

namespace App\Services\Analytics;

use App\Engines\Recommendation\RecommendationGenerationPipeline;
use App\Engines\Support\ApiEnvelope;
use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\StrategyEligibilityService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * F137 — Recommendation Preview (execution-grade contract over shared V1 decision logic).
 *
 * PD-17: uses RecommendationGenerationPipeline::decideForSecurity (no persist/cancel).
 * PD-10: must not call ensureActive / full generate.
 */
class RecommendationPreviewService
{
    public function __construct(
        protected RecommendationGenerationPipeline $pipeline,
        protected StrategyEligibilityService $eligibility,
    ) {}

    /**
     * @return array<string, mixed>|JsonResponse
     */
    public function forStock(PortfolioProfile $profile, Stock $stock, ?int $strategyId)
    {
        if ($strategyId === null || $strategyId < 1) {
            return ApiEnvelope::error(
                'STRATEGY_ID_REQUIRED',
                'Query parameter strategy_id is required and must identify a strategy owned by the active profile.',
                422,
            );
        }

        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('id', $strategyId)
            ->first();

        if (! $strategy) {
            throw new NotFoundHttpException('Strategy not found for the active profile.');
        }

        $version = TradingStrategyVersion::query()->find($strategy->active_version_id);
        if (! $version) {
            return ApiEnvelope::error(
                'STRATEGY_VERSION_MISSING',
                'Selected strategy has no active version.',
                422,
            );
        }

        $latestCycle = EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        $persisted = $this->findCurrentPersisted($profile, $stock, $version, $latestCycle);
        if ($persisted !== null) {
            return $this->payloadFromPersisted($profile, $stock, $strategy, $version, $persisted, $latestCycle);
        }

        $decision = $this->pipeline->decideForSecurity(
            $profile,
            (int) $stock->id,
            $latestCycle,
            $version,
        );

        if (! ($decision['available'] ?? false)) {
            return $this->unavailablePayload(
                $stock,
                $strategy,
                $version,
                $decision['evaluation_run'] ?? $latestCycle,
                $decision['unavailable_reasons'] ?? [[
                    'code' => 'UNAVAILABLE',
                    'message' => 'Recommendation preview is not available.',
                ]],
                $decision['eligibility'] ?? null,
            );
        }

        return $this->payloadFromDecision($stock, $strategy, $version, $decision);
    }

    protected function findCurrentPersisted(
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategyVersion $version,
        ?EvaluationRun $latestCycle,
    ): ?TradingRecommendation {
        if ($latestCycle === null) {
            return null;
        }

        $existing = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('security_id', $stock->id)
            ->where('strategy_version_id', $version->id)
            ->openList()
            ->with(['evaluationResult:id,evaluation_run_id'])
            ->orderByDesc('id')
            ->first();

        if (! $existing || $existing->evaluation_result_id === null) {
            return null;
        }

        $runId = (int) ($existing->evaluationResult?->evaluation_run_id ?? 0);
        if ($runId !== (int) $latestCycle->id) {
            return null;
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    protected function payloadFromDecision(
        Stock $stock,
        TradingStrategy $strategy,
        TradingStrategyVersion $version,
        array $decision,
    ): array {
        $draft = $decision['draft'];
        $action = (string) $decision['final_action'];
        $eligibility = $decision['eligibility'] ?? [];
        $screenerExplain = is_array($draft['screener_explain'] ?? null) ? $draft['screener_explain'] : [];

        return $this->buildPayload(
            available: true,
            unavailableReasons: [],
            stock: $stock,
            strategy: $strategy,
            version: $version,
            evaluationCycle: $decision['evaluation_run'],
            source: 'calculated',
            recommendation: TradingRecommendation::toF137Canonical($action),
            recommendationScore: isset($draft['score']) ? round((float) $draft['score'], 2) : null,
            suggestedAllocationPct: isset($draft['suggested_alloc']) ? round((float) $draft['suggested_alloc'], 4) : null,
            confidence: isset($draft['confidence']) ? round((float) $draft['confidence'], 4) : null,
            eligibilitySources: $screenerExplain,
            eligibilityRequired: in_array($eligibility['mode'] ?? 'unrestricted', ['screener_union'], true),
            reasonSummary: $decision['reasoning'] ?? null,
            scoringBreakdown: $draft['strategy_breakdown'] ?? null,
            portfolioAction: $action,
            recommendationId: null,
            status: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromPersisted(
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategy $strategy,
        TradingStrategyVersion $version,
        TradingRecommendation $rec,
        ?EvaluationRun $latestCycle,
    ): array {
        $evidence = is_array($rec->evidence) ? $rec->evidence : [];
        $eligibilityMeta = is_array($evidence['eligibility'] ?? null) ? $evidence['eligibility'] : [];
        $screeners = $eligibilityMeta['screeners'] ?? null;
        if (! is_array($screeners)) {
            $config = $version->config_json ?? [];
            $resolved = $this->eligibility->resolve($profile, is_array($config) ? $config : []);
            $screeners = $this->eligibility->explainForSecurity($resolved, (int) $stock->id);
            $mode = $resolved['mode'] ?? 'unrestricted';
        } else {
            $mode = $eligibilityMeta['mode'] ?? 'unrestricted';
        }

        $breakdown = $evidence['scoring']['breakdown']
            ?? $evidence['factor_breakdown']
            ?? null;

        return $this->buildPayload(
            available: true,
            unavailableReasons: [],
            stock: $stock,
            strategy: $strategy,
            version: $version,
            evaluationCycle: $latestCycle,
            source: 'persisted',
            recommendation: $rec->asF137Canonical(),
            recommendationScore: $rec->strategy_score !== null ? round((float) $rec->strategy_score, 2) : null,
            suggestedAllocationPct: $rec->suggested_allocation_pct !== null
                ? round((float) $rec->suggested_allocation_pct, 4)
                : null,
            confidence: $rec->confidence !== null ? round((float) $rec->confidence, 4) : null,
            eligibilitySources: is_array($screeners) ? $screeners : [],
            eligibilityRequired: in_array($mode, ['screener_union'], true),
            reasonSummary: $rec->reasoning,
            scoringBreakdown: is_array($breakdown) ? $breakdown : null,
            portfolioAction: $rec->portfolioAction(),
            recommendationId: $rec->id,
            status: $rec->status,
        );
    }

    /**
     * @param  list<array{code: string, message: string}>  $reasons
     * @param  array<string, mixed>|null  $eligibility
     * @return array<string, mixed>
     */
    protected function unavailablePayload(
        Stock $stock,
        TradingStrategy $strategy,
        TradingStrategyVersion $version,
        ?EvaluationRun $evaluationCycle,
        array $reasons,
        ?array $eligibility,
    ): array {
        $mode = $eligibility['mode'] ?? 'unrestricted';
        $screeners = [];
        if (is_array($eligibility)) {
            // Prefer empty list; explain is optional when unavailable.
            $screeners = [];
        }

        return $this->buildPayload(
            available: false,
            unavailableReasons: $reasons,
            stock: $stock,
            strategy: $strategy,
            version: $version,
            evaluationCycle: $evaluationCycle,
            source: null,
            recommendation: null,
            recommendationScore: null,
            suggestedAllocationPct: null,
            confidence: null,
            eligibilitySources: $screeners,
            eligibilityRequired: in_array($mode, ['screener_union'], true),
            reasonSummary: null,
            scoringBreakdown: null,
            portfolioAction: null,
            recommendationId: null,
            status: null,
        );
    }

    /**
     * @param  list<array{code: string, message: string}>  $unavailableReasons
     * @param  list<mixed>  $eligibilitySources
     * @return array<string, mixed>
     */
    protected function buildPayload(
        bool $available,
        array $unavailableReasons,
        Stock $stock,
        TradingStrategy $strategy,
        TradingStrategyVersion $version,
        ?EvaluationRun $evaluationCycle,
        ?string $source,
        ?string $recommendation,
        ?float $recommendationScore,
        ?float $suggestedAllocationPct,
        ?float $confidence,
        array $eligibilitySources,
        bool $eligibilityRequired,
        ?string $reasonSummary,
        mixed $scoringBreakdown,
        ?string $portfolioAction,
        ?int $recommendationId,
        ?string $status,
    ): array {
        $versionLabel = $version->version_label
            ?: ((string) $version->version).(str_contains((string) $version->version, '.') ? '' : '.0');

        return [
            'owner' => 'recommendation_engine',
            'available' => $available,
            'unavailable_reasons' => $unavailableReasons,
            // —— Authoritative execution section (PD-04) ——
            'execution' => [
                'recommendation' => $recommendation,
                'recommendation_score' => $recommendationScore,
                'suggested_allocation_pct' => $suggestedAllocationPct,
                'strategy' => [
                    'id' => $strategy->id,
                    'name' => $strategy->name,
                    'version_id' => $version->id,
                    'version_label' => $versionLabel,
                    'is_factory' => (bool) $strategy->is_factory,
                ],
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'source' => $source,
                'evaluation_cycle_id' => $evaluationCycle?->id,
            ],
            // —— Research metadata (PD-04) — not execution ——
            'research' => [
                'confidence' => $confidence,
                'confidence_unit' => '0_1',
                'eligibility_required' => $eligibilityRequired,
                'eligibility_sources' => $eligibilitySources,
                'reason_summary' => $reasonSummary,
                'scoring_breakdown' => $scoringBreakdown,
                'portfolio_action' => $portfolioAction,
                'recommendation_id' => $recommendationId,
                'status' => $status,
            ],
            // Flat aliases for Watchlist / transitional consumers (mirror execution when available).
            'recommendation' => $recommendation,
            'recommendation_score' => $recommendationScore,
            'suggested_allocation_pct' => $suggestedAllocationPct,
            'strategy' => [
                'id' => $strategy->id,
                'name' => $strategy->name,
                'version_id' => $version->id,
                'version_label' => $versionLabel,
                'is_factory' => (bool) $strategy->is_factory,
            ],
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'source' => $source,
            'evaluation_cycle_id' => $evaluationCycle?->id,
            'confidence' => $confidence,
            'eligibility_sources' => $eligibilitySources,
            'reason_summary' => $reasonSummary,
            'recommendation_id' => $recommendationId,
            'status' => $status,
        ];
    }
}
