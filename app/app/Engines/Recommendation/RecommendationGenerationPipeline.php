<?php

namespace App\Engines\Recommendation;

use App\Engines\Recommendation\Allocation\CapitalAllocationStrategy;
use App\Engines\Recommendation\Allocation\ScorePriorityCapitalAllocator;
use App\Engines\Strategy\ExitStrategyEvaluator;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Services\CashManagementService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;
use App\Services\Analytics\MarketAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TD-002: recommendation generation stages extracted out of RecommendationEngine::generate().
 *
 * Orchestration is staged as:
 * prepareContext → cancelStaleRecommendations → buildDrafts → rankDrafts → allocateCapital → persistDrafts.
 *
 * Behaviour is copied verbatim from the previous inline implementation; no logic changes.
 */
class RecommendationGenerationPipeline
{
    protected CapitalAllocationStrategy $allocator;

    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
        protected CashManagementService $cash,
        protected StrategyConfigurationService $strategies,
        protected StrategyEligibilityService $eligibility,
        protected MarketAnalyticsService $marketAnalytics,
        ?CapitalAllocationStrategy $allocator = null,
    ) {
        $this->allocator = $allocator ?? new ScorePriorityCapitalAllocator();
    }

    /**
     * @return array{
     *     recommendations: list<TradingRecommendation>,
     *     batch_id: string,
     *     cash: array{cash_balance: float, reserved_cash: float, available_investable_cash: float},
     *     strategy: array{version_id: int, version: int, name: string}
     * }
     */
    public function run(PortfolioProfile $profile, ?EvaluationRun $evaluationRun = null): array
    {
        $ctx = $this->prepareContext($profile, $evaluationRun);

        $created = [];
        DB::transaction(function () use ($profile, $ctx, &$created) {
            $this->cancelStaleRecommendations($profile);

            $drafts = $this->buildDrafts($ctx);
            $drafts = $this->rankDrafts($drafts, $ctx);
            $allocations = $this->allocateCapital($drafts, $ctx);
            $created = $this->persistDrafts($drafts, $allocations, $ctx, $profile);
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendations generated', [
            'profile_id' => $profile->id,
            'count' => count($created),
            'evaluation_run_id' => $ctx['evaluation_run']->id,
            'strategy_version_id' => $ctx['strategy_version']->id,
            'available_investable_cash' => $ctx['available_cash'],
        ]);

        return [
            'recommendations' => $created,
            'batch_id' => 'eval-'.$ctx['evaluation_run']->id.'-'.now()->format('YmdHis'),
            'cash' => $ctx['cash_summary'],
            'strategy' => [
                'version_id' => $ctx['strategy_version']->id,
                'version' => $ctx['strategy_version']->version,
                'name' => $ctx['strategy']?->name ?? 'Strategy',
            ],
        ];
    }

    /**
     * Resolve evaluation run, strategy config, thresholds, market gates/multipliers,
     * cash headroom, eligibility, and current holdings snapshot.
     *
     * @return array<string, mixed>
     */
    protected function prepareContext(PortfolioProfile $profile, ?EvaluationRun $evaluationRun): array
    {
        $evaluationRun ??= EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $evaluationRun) {
            throw new \RuntimeException('No completed evaluation run available for recommendations.');
        }

        $strategyVersion = $this->strategies->ensureActive($profile);
        $strategy = $strategyVersion->strategy;
        $config = $strategyVersion->config_json ?? $this->strategies->defaultConfig();

        $thresholds = $config['thresholds'] ?? [];
        $buyMin = (float) ($thresholds['open_position'] ?? 65);
        $increaseMin = (float) ($thresholds['increase_position'] ?? $buyMin);
        $watchMin = (float) ($thresholds['watch'] ?? 45);
        $sellMax = (float) ($thresholds['exit_position'] ?? 35);
        $reduceMax = (float) ($thresholds['reduce_position'] ?? $sellMax);
        $veryStrongHigh = (float) ($thresholds['very_strong_high'] ?? 85);
        $veryStrongLow = (float) ($thresholds['very_strong_low'] ?? 15);

        $behaviour = $config['recommendation_behaviour'] ?? [];
        $expiryHours = (int) ($behaviour['expiry_hours'] ?? 48);
        $allowIncrease = (bool) ($behaviour['allow_increase_position'] ?? true);
        $allowReduce = (bool) ($behaviour['allow_reduce_position'] ?? true);
        $maxConcurrent = (int) ($behaviour['max_concurrent_recommendations'] ?? 100);

        $portfolioRules = $config['portfolio_rules'] ?? [];
        $defaultPct = (float) ($portfolioRules['default_position_size_pct'] ?? 5);
        $maxPct = (float) ($portfolioRules['max_position_size_pct'] ?? 10);
        $allocationBand = (float) ($portfolioRules['allocation_band_pct'] ?? 1.0);
        $maxNewPositions = (int) ($portfolioRules['max_new_positions_per_cycle'] ?? 50);
        $minCashReservePct = (float) ($portfolioRules['min_cash_reserve_pct'] ?? 0);
        $maxCashDeployPct = (float) ($portfolioRules['max_cash_deployment_pct'] ?? 100);

        // SD-032: consume Market Analysis Engine — never recalculate market metrics here.
        $market = [];
        try {
            $market = $this->marketAnalytics->latest();
        } catch (Throwable) {
            $market = [];
        }
        $marketMult = (float) ($market['allocation_multiplier'] ?? 1.0);
        $marketAllowsEntry = (bool) ($market['new_entry_allowed'] ?? true);
        $marketGates = is_array($config['market_gates'] ?? null) ? $config['market_gates'] : [];
        if (($marketGates['enabled'] ?? false) === true) {
            $minSentiment = $marketGates['min_sentiment'] ?? null;
            $allowedPhases = $marketGates['allowed_phases'] ?? null;
            $sentimentScore = (float) ($market['sentiment']['score'] ?? 50);
            $phase = (string) ($market['market_phase'] ?? '');
            if ($minSentiment !== null && $sentimentScore < (float) $minSentiment) {
                $marketAllowsEntry = false;
            }
            if (is_array($allowedPhases) && $allowedPhases !== [] && ! in_array($phase, $allowedPhases, true)) {
                $marketAllowsEntry = false;
            }
            if (isset($marketGates['max_risk_raw']) && is_numeric($market['risk']['raw_risk'] ?? null)) {
                if ((float) $market['risk']['raw_risk'] > (float) $marketGates['max_risk_raw']) {
                    $marketAllowsEntry = false;
                    $marketMult = min($marketMult, 0.5);
                }
            }
        }
        $defaultPct = round($defaultPct * $marketMult, 4);
        $maxPct = round($maxPct * $marketMult, 4);

        $riskCfg = $config['risk'] ?? [];
        $allocCfg = $config['capital_allocation'] ?? [];

        $cashSummary = $this->cash->summary($profile);
        $availableCash = (float) $cashSummary['available_investable_cash'];
        if ($minCashReservePct > 0 && $cashSummary['cash_balance'] > 0) {
            $reserveFloor = round(((float) $cashSummary['cash_balance']) * ($minCashReservePct / 100.0), 4);
            $availableCash = max(0.0, $availableCash - $reserveFloor);
        }
        if ($maxCashDeployPct < 100) {
            $cap = round(((float) $cashSummary['cash_balance']) * ($maxCashDeployPct / 100.0), 4);
            $availableCash = min($availableCash, max(0.0, $cap));
        }

        $results = EvaluationResult::query()
            ->where('evaluation_run_id', $evaluationRun->id)
            ->with(['candidate.security'])
            ->orderBy('rank')
            ->get();

        $eligibility = $this->eligibility->resolve($profile, is_array($config) ? $config : []);
        $eligibleSet = array_fill_keys($eligibility['eligible_security_ids'] ?? [], true);
        $eligibilityRestricted = in_array($eligibility['mode'] ?? 'unrestricted', ['screener_union'], true);

        $heldQty = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'stock_id')
            ->all();

        $portfolioValue = 0.0;
        $allocationByStock = [];
        try {
            $summary = $this->portfolio->calculateForProfile($profile);
            $portfolioValue = (float) ($summary['portfolio_value'] ?? 0);
            foreach ($summary['holdings'] ?? [] as $h) {
                $allocationByStock[(int) $h['stock_id']] = [
                    'allocation_pct' => (float) ($h['allocation_market_percent'] ?? 0),
                    'quantity' => (float) ($h['quantity'] ?? 0),
                    'market_value' => (float) ($h['market_value'] ?? 0),
                    'latest_close' => (float) ($h['latest_close'] ?? 0),
                    'unrealized_pnl_pct' => isset($h['unrealized_pnl_percent'])
                        ? (float) $h['unrealized_pnl_percent']
                        : (isset($h['gain_loss_percent']) ? (float) $h['gain_loss_percent'] : null),
                ];
            }
        } catch (Throwable) {
            $portfolioValue = 0.0;
        }

        $expiresAt = Carbon::now()->addHours($expiryHours);

        return [
            'evaluation_run' => $evaluationRun,
            'strategy_version' => $strategyVersion,
            'strategy' => $strategy,
            'config' => $config,
            'buy_min' => $buyMin,
            'increase_min' => $increaseMin,
            'watch_min' => $watchMin,
            'sell_max' => $sellMax,
            'reduce_max' => $reduceMax,
            'very_strong_high' => $veryStrongHigh,
            'very_strong_low' => $veryStrongLow,
            'allow_increase' => $allowIncrease,
            'allow_reduce' => $allowReduce,
            'max_concurrent' => $maxConcurrent,
            'default_pct' => $defaultPct,
            'max_pct' => $maxPct,
            'allocation_band' => $allocationBand,
            'max_new_positions' => $maxNewPositions,
            'market' => $market,
            'market_allows_entry' => $marketAllowsEntry,
            'risk_cfg' => $riskCfg,
            'alloc_cfg' => $allocCfg,
            'cash_summary' => $cashSummary,
            'available_cash' => $availableCash,
            'results' => $results,
            'eligibility' => $eligibility,
            'eligible_set' => $eligibleSet,
            'eligibility_restricted' => $eligibilityRestricted,
            'held_qty' => $heldQty,
            'portfolio_value' => $portfolioValue,
            'allocation_by_stock' => $allocationByStock,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Cancel any recommendations still open from a previous generation cycle.
     */
    protected function cancelStaleRecommendations(PortfolioProfile $profile): void
    {
        TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PUBLISHED,
                'active',
            ])
            ->update(['status' => TradingRecommendation::STATUS_CANCELLED]);
    }

    /**
     * Score every evaluation result and build a draft recommendation for it.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function buildDrafts(array $ctx): array
    {
        $results = $ctx['results'];
        $heldQty = $ctx['held_qty'];
        $allocationByStock = $ctx['allocation_by_stock'];
        $buyMin = $ctx['buy_min'];
        $increaseMin = $ctx['increase_min'];
        $watchMin = $ctx['watch_min'];
        $sellMax = $ctx['sell_max'];
        $reduceMax = $ctx['reduce_max'];
        $veryStrongHigh = $ctx['very_strong_high'];
        $veryStrongLow = $ctx['very_strong_low'];
        $defaultPct = $ctx['default_pct'];
        $maxPct = $ctx['max_pct'];
        $allocationBand = $ctx['allocation_band'];
        $portfolioValue = $ctx['portfolio_value'];
        $riskCfg = $ctx['risk_cfg'];
        $config = $ctx['config'];
        $allowIncrease = $ctx['allow_increase'];
        $allowReduce = $ctx['allow_reduce'];
        $eligibility = $ctx['eligibility'];
        $eligibleSet = $ctx['eligible_set'];
        $eligibilityRestricted = $ctx['eligibility_restricted'];
        $marketAllowsEntry = $ctx['market_allows_entry'];

        $drafts = [];
        foreach ($results as $index => $result) {
            $securityId = (int) $result->candidate->security_id;
            $qtyHeld = (float) ($heldQty[$securityId] ?? 0);
            $isHeld = $qtyHeld > 0;
            $isEligible = ! $eligibilityRestricted || isset($eligibleSet[$securityId]);

            // SD-030: new entries only from Screener-eligible candidates; holdings always reviewed for exits.
            if (! $isEligible && ! $isHeld) {
                continue;
            }

            $factorScores = $result->evidence['indicator_scores']
                ?? $result->evidence['factor_scores']
                ?? $result->evidence['component_scores']
                ?? [];
            $scored = $this->strategies->score(is_array($factorScores) ? $factorScores : [], $config);
            $score = (float) $scored['overall_score'];
            $confidence = (float) $result->confidence;
            $holdingMeta = $allocationByStock[$securityId] ?? null;
            $currentAlloc = $holdingMeta ? (float) $holdingMeta['allocation_pct'] : 0.0;
            $atrPct = $result->evidence['indicators']['atr_pct'] ?? null;
            $close = $result->evidence['indicators']['close'] ?? null;
            $referencePrice = is_numeric($close)
                ? (float) $close
                : ($holdingMeta['latest_close'] ?? null);
            if ($referencePrice === null || $referencePrice <= 0) {
                $referencePrice = $this->latestClose($securityId);
            }

            $bandPct = $this->strategies->allocationPctForScore($score, $config);
            $targetAlloc = min($maxPct, $bandPct > 0 ? $bandPct : $defaultPct);

            $screenerExplain = $this->eligibility->explainForSecurity($eligibility, $securityId);
            $exitEval = ['triggered' => false, 'matched' => [], 'status' => 'Not Triggered'];
            if ($isHeld) {
                $exitEval = ExitStrategyEvaluator::evaluate(
                    is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [],
                    [
                        'overall_score' => $score,
                        'indicator_scores' => is_array($factorScores) ? $factorScores : [],
                        'indicators' => $result->evidence['indicators'] ?? [],
                        'unrealized_pnl_pct' => $holdingMeta['unrealized_pnl_pct'] ?? null,
                    ]
                );
            }

            $opinion = $this->buildMarketOpinion(
                $score,
                $confidence,
                $buyMin,
                $watchMin,
                $sellMax,
                $veryStrongHigh,
                $veryStrongLow,
                $result,
            );

            $risk = $this->riskLevel($atrPct, $riskCfg);
            $action = $this->decidePortfolioAction(
                $opinion,
                $isHeld,
                $currentAlloc,
                $targetAlloc,
                $allocationBand,
                $risk,
                $score,
                $buyMin,
                $increaseMin,
                $sellMax,
                $reduceMax,
                $allowIncrease,
                $allowReduce,
            );

            // Exit strategy overrides: force EXIT when rules trigger.
            if ($isHeld && ($exitEval['triggered'] ?? false)) {
                $action = 'EXIT_POSITION';
                if (is_array($opinion)) {
                    $dir = (string) ($opinion['direction'] ?? '');
                    if (! in_array($dir, ['STRONG_SELL', 'SELL'], true)) {
                        $opinion['direction'] = 'SELL';
                        $opinion['strength'] = $opinion['strength'] ?? 'moderate';
                    }
                }
            }

            // Non-eligible holdings: only emit if exit triggered; otherwise HOLD.
            if ($isHeld && ! $isEligible && ! ($exitEval['triggered'] ?? false)) {
                $action = 'HOLD_POSITION';
            }

            // Non-eligible cannot open / increase.
            if (! $isEligible && in_array($action, ['OPEN_POSITION', 'INCREASE_POSITION'], true)) {
                $action = $isHeld ? 'HOLD_POSITION' : 'WATCH';
            }

            // Market Analysis gate: block / demote new entries when market phase/risk forbids.
            if (! $marketAllowsEntry && in_array($action, ['OPEN_POSITION', 'INCREASE_POSITION'], true)) {
                $action = $isHeld ? 'HOLD_POSITION' : 'WATCH';
            }

            $plan = null;
            $suggestedAlloc = $currentAlloc;
            $positionSize = null;
            if (in_array($action, TradingRecommendation::ACTIONABLE_ACTIONS, true)) {
                $plan = $this->buildExecutionPlan(
                    $action,
                    $portfolioValue,
                    $currentAlloc,
                    $targetAlloc,
                    $maxPct,
                    $qtyHeld,
                    $referencePrice,
                    $risk,
                );
                $suggestedAlloc = (float) ($plan['suggested_allocation_pct'] ?? $targetAlloc);
                $positionSize = isset($plan['suggested_investment_amount'])
                    ? (float) $plan['suggested_investment_amount']
                    : null;
            } else {
                $suggestedAlloc = $isHeld ? $currentAlloc : 0.0;
            }

            $priority = (int) max(1, min(100, round($score)));

            $drafts[] = [
                'key' => $index,
                'result' => $result,
                'security_id' => $securityId,
                'score' => $score,
                'strategy_breakdown' => $scored['breakdown'],
                'confidence' => $confidence,
                'qty_held' => $qtyHeld,
                'is_held' => $isHeld,
                'is_eligible' => $isEligible,
                'screener_explain' => $screenerExplain,
                'exit_eval' => $exitEval,
                'current_alloc' => $currentAlloc,
                'target_alloc' => $targetAlloc,
                'suggested_alloc' => $suggestedAlloc,
                'reference_price' => $referencePrice,
                'opinion' => $opinion,
                'action' => $action,
                'plan' => $plan,
                'position_size' => $positionSize,
                'priority' => $priority,
                'risk' => $risk,
                'factor_scores' => $factorScores,
            ];
        }

        return $drafts;
    }

    /**
     * Sort drafts by score, then the configured tie-break rule.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function rankDrafts(array $drafts, array $ctx): array
    {
        $allocCfg = $ctx['alloc_cfg'];
        $tieBreak = (string) ($allocCfg['tie_break'] ?? 'highest_score');

        usort($drafts, function (array $a, array $b) use ($tieBreak): int {
            $scoreCmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            if ($tieBreak === 'highest_relative_strength') {
                return (($b['factor_scores']['relative_strength'] ?? 0) <=> ($a['factor_scores']['relative_strength'] ?? 0));
            }
            if ($tieBreak === 'highest_momentum') {
                $bM = $b['factor_scores']['momentum_score'] ?? $b['factor_scores']['momentum'] ?? 0;
                $aM = $a['factor_scores']['momentum_score'] ?? $a['factor_scores']['momentum'] ?? 0;

                return ($bM <=> $aM);
            }
            if ($tieBreak === 'highest_breakout') {
                $bB = $b['factor_scores']['breakout_score'] ?? $b['factor_scores']['pattern_bonus'] ?? 0;
                $aB = $a['factor_scores']['breakout_score'] ?? $a['factor_scores']['pattern_bonus'] ?? 0;

                return ($bB <=> $aB);
            }

            return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
        });

        return $drafts;
    }

    /**
     * Build buy drafts from ranked candidates and allocate available cash across them.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return array<int|string, array{allocated_amount: float, quantity: int}>
     */
    protected function allocateCapital(array $drafts, array $ctx): array
    {
        $portfolioValue = $ctx['portfolio_value'];
        $maxPct = $ctx['max_pct'];
        $maxNewPositions = $ctx['max_new_positions'];
        $allocCfg = $ctx['alloc_cfg'];
        $availableCash = $ctx['available_cash'];

        $buyDrafts = [];
        $maxPositionAmount = $portfolioValue > 0 ? round($portfolioValue * ($maxPct / 100.0), 4) : null;
        $newOpenCount = 0;
        foreach ($drafts as $draft) {
            if (! in_array($draft['action'], ['OPEN_POSITION', 'INCREASE_POSITION'], true)) {
                continue;
            }
            if ($draft['action'] === 'OPEN_POSITION') {
                if ($newOpenCount >= $maxNewPositions) {
                    continue;
                }
                $newOpenCount++;
            }
            $desired = (float) ($draft['plan']['suggested_investment_amount'] ?? $draft['position_size'] ?? 0);
            $buyDrafts[] = [
                'key' => $draft['key'],
                'score' => $draft['score'],
                'confidence' => $draft['confidence'],
                'priority' => $draft['priority'],
                'desired_amount' => max(0.0, $desired),
                'reference_price' => (float) ($draft['reference_price'] ?? 0),
                'action' => $draft['action'],
                'max_position_amount' => $maxPositionAmount,
            ];
        }

        $allocStrategy = (string) ($allocCfg['strategy'] ?? 'proportional');
        if ($allocStrategy === 'equal_weight') {
            foreach ($buyDrafts as &$bd) {
                $bd['score'] = 1.0;
            }
            unset($bd);
        } elseif ($allocStrategy === 'simple_ranking') {
            // Greedy: highest score gets full desired until cash runs out (handled by allocator with extreme weights).
            $rank = count($buyDrafts);
            foreach ($buyDrafts as &$bd) {
                $bd['score'] = (float) $rank;
                $rank--;
            }
            unset($bd);
        }

        return $this->allocator->allocate($availableCash, $buyDrafts);
    }

    /**
     * Apply allocation results to ranked drafts and persist TradingRecommendation rows.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<int|string, array{allocated_amount: float, quantity: int}>  $allocations
     * @param  array<string, mixed>  $ctx
     * @return list<TradingRecommendation>
     */
    protected function persistDrafts(array $drafts, array $allocations, array $ctx, PortfolioProfile $profile): array
    {
        $maxConcurrent = $ctx['max_concurrent'];
        $strategyVersion = $ctx['strategy_version'];
        $eligibility = $ctx['eligibility'];
        $market = $ctx['market'];
        $marketAllowsEntry = $ctx['market_allows_entry'];
        $cashSummary = $ctx['cash_summary'];
        $expiresAt = $ctx['expires_at'];

        $created = [];
        $persisted = 0;
        foreach ($drafts as $draft) {
            if ($persisted >= $maxConcurrent) {
                break;
            }
            $action = $draft['action'];
            $plan = $draft['plan'];
            $suggestedAlloc = $draft['suggested_alloc'];
            $positionSize = $draft['position_size'];
            $suggestedAllocationAmount = null;
            $capitalAllocationMeta = null;

            if (in_array($action, ['OPEN_POSITION', 'INCREASE_POSITION'], true)) {
                $alloc = $allocations[$draft['key']] ?? ['allocated_amount' => 0.0, 'quantity' => 0];
                $qty = (int) ($alloc['quantity'] ?? 0);
                $amount = round((float) ($alloc['allocated_amount'] ?? 0), 4);
                $desiredAmount = (float) ($draft['plan']['suggested_investment_amount'] ?? $draft['position_size'] ?? 0);

                if ($qty < 1 || $amount <= 0) {
                    $capitalAllocationMeta = [
                        'status' => 'unfunded',
                        'desired_amount' => $desiredAmount,
                        'allocated_amount' => 0.0,
                        'quantity' => 0,
                    ];
                    $action = 'WATCH';
                    $plan = is_array($draft['plan']) ? array_merge($draft['plan'], [
                        'capital_allocation' => $capitalAllocationMeta,
                        'suggested_quantity' => 0,
                        'suggested_investment_amount' => 0.0,
                    ]) : ['capital_allocation' => $capitalAllocationMeta];
                    $positionSize = null;
                    $suggestedAllocationAmount = 0.0;
                    $suggestedAlloc = $draft['is_held'] ? $draft['current_alloc'] : 0.0;
                } else {
                    $capitalAllocationMeta = [
                        'status' => 'funded',
                        'desired_amount' => $desiredAmount,
                        'allocated_amount' => $amount,
                        'quantity' => $qty,
                    ];
                    $plan = is_array($plan) ? $plan : [];
                    $plan['suggested_quantity'] = $qty;
                    $plan['suggested_investment_amount'] = $amount;
                    $plan['capital_allocation'] = $capitalAllocationMeta;
                    if (isset($plan['position_after']) && is_array($plan['position_after'])) {
                        $plan['position_after']['quantity_delta'] = $qty;
                    }
                    $positionSize = $amount;
                    $suggestedAllocationAmount = $amount;
                }
            } elseif (is_array($plan) && isset($plan['suggested_investment_amount'])) {
                $suggestedAllocationAmount = (float) $plan['suggested_investment_amount'];
            }

            $reasoning = $this->buildReasoning(
                $draft['opinion'],
                $action,
                $draft['current_alloc'],
                $draft['target_alloc'],
                $draft['is_held'],
                $draft['risk'],
            );

            $evidence = [
                'score' => $draft['score'],
                'strategy_score' => $draft['score'],
                'strategy_version_id' => $strategyVersion->id,
                'strategy_version' => $strategyVersion->version,
                'eligibility' => [
                    'mode' => $eligibility['mode'] ?? 'unrestricted',
                    'is_eligible' => (bool) ($draft['is_eligible'] ?? true),
                    'screeners' => $draft['screener_explain'] ?? [],
                ],
                'factor_breakdown' => $draft['strategy_breakdown'],
                'scoring' => [
                    'overall_score' => $draft['score'],
                    'breakdown' => $draft['strategy_breakdown'],
                ],
                'exit_strategy' => $draft['exit_eval'] ?? ['status' => 'Not Triggered'],
                'rank' => $draft['result']->rank,
                'passed_rules' => $draft['result']->passed_rules,
                'failed_rules' => $draft['result']->failed_rules,
                'indicators' => $draft['result']->evidence['indicators'] ?? [],
                'factor_scores' => $draft['factor_scores'],
                'discovery' => $draft['result']->evidence['discovery'] ?? [],
                'held' => $draft['is_held'],
                'market_opinion' => $draft['opinion'],
                'portfolio_action' => $action,
                'portfolio_decision' => $action,
                'market_analysis' => [
                    'market_phase' => $market['market_phase'] ?? null,
                    'sentiment_score' => $market['sentiment']['score'] ?? null,
                    'sentiment_label' => $market['sentiment']['label'] ?? null,
                    'risk_label' => $market['risk']['label'] ?? null,
                    'allocation_multiplier' => $market['allocation_multiplier'] ?? 1,
                    'new_entry_allowed' => $marketAllowsEntry,
                ],
            ];
            if ($capitalAllocationMeta !== null) {
                $evidence['capital_allocation'] = $capitalAllocationMeta;
            }

            $created[] = TradingRecommendation::query()->create([
                'profile_id' => $profile->id,
                'evaluation_result_id' => $draft['result']->id,
                'strategy_version_id' => $strategyVersion->id,
                'security_id' => $draft['security_id'],
                'recommendation_type' => $action,
                'market_opinion' => $draft['opinion'],
                'execution_plan' => $plan,
                'priority' => $draft['priority'],
                'strategy_score' => $draft['score'],
                'confidence' => $draft['confidence'],
                'risk_level' => $draft['risk'],
                'suggested_position_size' => $positionSize,
                'suggested_allocation_amount' => $suggestedAllocationAmount,
                'reference_price' => $draft['reference_price'],
                'current_allocation_pct' => round($draft['current_alloc'], 4),
                'target_allocation_pct' => round($draft['target_alloc'], 4),
                'suggested_allocation_pct' => round($suggestedAlloc, 4),
                'status' => TradingRecommendation::initialStatusForAction($action),
                'evidence' => $evidence,
                'failed_checks' => $draft['result']->failed_rules ?? [],
                'reasoning' => $reasoning,
                'cash_balance_at_generation' => $cashSummary['cash_balance'],
                'reserved_cash_at_generation' => $cashSummary['reserved_cash'],
                'available_cash_at_generation' => $cashSummary['available_investable_cash'],
                'reservation_status' => TradingRecommendation::RESERVATION_NONE,
                'reserved_amount' => 0,
                'version' => 4,
                'expires_at' => $expiresAt,
                'generated_at' => now(),
            ]);
            $persisted++;
        }

        return $created;
    }

    /**
     * @return array{direction: string, strength: string, confidence: float, evidence: array<string,mixed>}
     */
    protected function buildMarketOpinion(
        float $score,
        float $confidence,
        float $buyMin,
        float $watchMin,
        float $sellMax,
        float $veryStrongHigh,
        float $veryStrongLow,
        EvaluationResult $result,
    ): array {
        if ($score >= $buyMin) {
            $direction = 'Bullish';
        } elseif ($score <= $sellMax) {
            $direction = 'Bearish';
        } else {
            $direction = 'Neutral';
        }

        $strength = match (true) {
            $score >= $veryStrongHigh || $score <= $veryStrongLow => 'Very Strong',
            $score >= $buyMin || $score <= $sellMax => 'Strong',
            $score >= $watchMin || $score <= ($sellMax + 10) => 'Moderate',
            default => 'Weak',
        };

        return [
            'direction' => $direction,
            'strength' => $strength,
            'confidence' => round($confidence, 4),
            'score' => $score,
            'evidence' => [
                'passed_rules' => $result->passed_rules ?? [],
                'failed_rules' => $result->failed_rules ?? [],
                'indicators' => $result->evidence['indicators'] ?? [],
                'rank' => $result->rank,
            ],
        ];
    }

    /**
     * @param  array{direction: string, strength: string, confidence: float}  $opinion
     */
    protected function decidePortfolioAction(
        array $opinion,
        bool $isHeld,
        float $currentAlloc,
        float $targetAlloc,
        float $band,
        string $risk,
        float $score,
        float $buyMin,
        float $increaseMin,
        float $sellMax,
        float $reduceMax,
        bool $allowIncrease,
        bool $allowReduce,
    ): string {
        $direction = $opinion['direction'];
        $strength = $opinion['strength'];
        $strongBull = $direction === 'Bullish' && in_array($strength, ['Strong', 'Very Strong'], true);
        $strongBear = $direction === 'Bearish' && in_array($strength, ['Strong', 'Very Strong'], true);
        $overTarget = $currentAlloc > ($targetAlloc + $band);
        $underTarget = $currentAlloc < ($targetAlloc - $band);
        $highRisk = $risk === 'high';

        if (! $isHeld) {
            if ($strongBull || $score >= $buyMin) {
                return 'OPEN_POSITION';
            }

            return 'WATCH';
        }

        // Held
        if ($strongBear || $score <= $sellMax) {
            return 'EXIT_POSITION';
        }

        if ($allowReduce && ($direction === 'Bearish' || $score <= $reduceMax || ($highRisk && $overTarget))) {
            return 'REDUCE_POSITION';
        }

        if ($allowReduce && $overTarget && ($direction !== 'Bullish' || $highRisk)) {
            return 'REDUCE_POSITION';
        }

        if ($allowIncrease && $direction === 'Bullish' && $underTarget && $score >= $increaseMin) {
            return 'INCREASE_POSITION';
        }

        if ($direction === 'Bullish' || $score >= $buyMin) {
            return 'HOLD_POSITION';
        }

        return 'HOLD_POSITION';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildExecutionPlan(
        string $action,
        float $portfolioValue,
        float $currentAlloc,
        float $targetAlloc,
        float $maxPct,
        float $qtyHeld,
        ?float $price,
        string $risk,
    ): array {
        $price = $price !== null && $price > 0 ? $price : null;
        $capAlloc = min($maxPct, $targetAlloc);
        $plan = [
            'action' => $action,
            'suggested_target_allocation_pct' => round($capAlloc, 4),
            'risk_explanation' => $this->riskExplanation($action, $risk, $currentAlloc, $capAlloc),
        ];

        if ($action === 'OPEN_POSITION' || $action === 'INCREASE_POSITION') {
            $suggestedAlloc = $action === 'OPEN_POSITION' ? $capAlloc : max($currentAlloc, $capAlloc);
            if ($action === 'INCREASE_POSITION') {
                $suggestedAlloc = min($maxPct, max($currentAlloc, $capAlloc));
            }
            $gapPct = max(0, $suggestedAlloc - $currentAlloc);
            $amount = $portfolioValue > 0 ? round($portfolioValue * ($gapPct / 100.0), 2) : null;
            $qty = ($amount !== null && $price) ? $this->wholeShareQuantity($amount / $price) : null;
            if ($qty !== null && $price && $qty > 0) {
                $amount = round($qty * $price, 2);
            }
            $plan['suggested_allocation_pct'] = round($suggestedAlloc, 4);
            $plan['suggested_investment_amount'] = $amount;
            $plan['suggested_quantity'] = $qty;
            $plan['side'] = 'buy';
            $plan['position_after'] = [
                'allocation_pct' => round($suggestedAlloc, 4),
                'quantity_delta' => $qty,
            ];
        } elseif ($action === 'REDUCE_POSITION') {
            $suggestedAlloc = max(0, min($currentAlloc, $capAlloc));
            if ($suggestedAlloc >= $currentAlloc - 0.01) {
                // Still reduce something when over-risk: sell ~30% of position.
                $sellPctOfPosition = 30.0;
                $suggestedAlloc = round($currentAlloc * (1 - $sellPctOfPosition / 100.0), 4);
            }
            $sellFraction = $currentAlloc > 0 ? max(0, ($currentAlloc - $suggestedAlloc) / $currentAlloc) : 0;
            $sharesToSell = $this->wholeShareQuantity($qtyHeld * $sellFraction);
            // Never suggest selling more than held (whole shares).
            $heldWhole = $this->wholeShareQuantity($qtyHeld);
            if ($sharesToSell > $heldWhole) {
                $sharesToSell = $heldWhole;
            }
            $plan['suggested_allocation_pct'] = round($suggestedAlloc, 4);
            $plan['suggested_sell_pct_of_position'] = round($sellFraction * 100, 2);
            $plan['suggested_shares_to_sell'] = $sharesToSell;
            $plan['suggested_quantity'] = $sharesToSell;
            $plan['side'] = 'sell';
            $plan['position_after'] = [
                'allocation_pct' => round($suggestedAlloc, 4),
                'quantity' => max(0, $heldWhole - $sharesToSell),
            ];
        } elseif ($action === 'EXIT_POSITION') {
            $heldWhole = $this->wholeShareQuantity($qtyHeld);
            $plan['suggested_allocation_pct'] = 0.0;
            $plan['suggested_sell_pct_of_position'] = 100.0;
            $plan['suggested_shares_to_sell'] = $heldWhole;
            $plan['suggested_quantity'] = $heldWhole;
            $plan['side'] = 'sell';
            $plan['position_after'] = [
                'allocation_pct' => 0.0,
                'quantity' => 0,
            ];
        }

        return $plan;
    }

    /**
     * NSE/BSE equity lots are whole shares — round to nearest integer (never negative).
     */
    protected function wholeShareQuantity(float $qty): int
    {
        return (int) max(0, (int) round($qty));
    }

    protected function riskExplanation(string $action, string $risk, float $current, float $target): string
    {
        return match ($action) {
            'OPEN_POSITION' => "Open toward ~{$target}% of portfolio (risk: {$risk}). Respects max position size.",
            'INCREASE_POSITION' => "Increase from {$current}% toward ~{$target}% (risk: {$risk}).",
            'REDUCE_POSITION' => "Reduce from {$current}% toward ~{$target}% (risk: {$risk}).",
            'EXIT_POSITION' => "Exit full position (risk: {$risk}).",
            default => "Risk level: {$risk}.",
        };
    }

    /**
     * @param  array{direction: string, strength: string}  $opinion
     */
    protected function buildReasoning(
        array $opinion,
        string $action,
        float $currentAlloc,
        float $targetAlloc,
        bool $isHeld,
        string $risk,
    ): string {
        $parts = [
            "Market: {$opinion['direction']} ({$opinion['strength']}).",
            $isHeld ? "Held at {$currentAlloc}% vs target {$targetAlloc}%." : 'No current holding.',
            "Portfolio action: {$action}.",
            "Risk: {$risk}.",
        ];

        return implode(' ', $parts);
    }

    protected function latestClose(int $securityId): ?float
    {
        $close = StockPrice::query()
            ->where('stock_id', $securityId)
            ->orderByDesc('price_date')
            ->value('close_price');

        return $close !== null ? (float) $close : null;
    }

    /**
     * @param  array<string,mixed>  $riskCfg
     */
    protected function riskLevel(mixed $atrPct, array $riskCfg): string
    {
        if (! is_numeric($atrPct)) {
            return 'medium';
        }
        $v = (float) $atrPct;
        if ($v >= (float) ($riskCfg['high_atr_pct'] ?? 4.0)) {
            return 'high';
        }
        if ($v >= (float) ($riskCfg['medium_atr_pct'] ?? 2.0)) {
            return 'medium';
        }

        return 'low';
    }
}
