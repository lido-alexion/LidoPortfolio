<?php

namespace App\Engines\Recommendation;

use App\Engines\Recommendation\Allocation\CapitalAllocationStrategy;
use App\Engines\Recommendation\Allocation\ScorePriorityCapitalAllocator;
use App\Engines\Strategy\ExitStrategyEvaluator;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\StockPrice;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;
use App\Services\Analytics\MarketAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Recommendation Engine — stages (SD-030 / SD-032):
 * 1) Receive candidates from Strategy Screeners (eligibility)
 * 2) Strategy weighted scoring (eligible stocks only for new entries)
 * 3) Market Opinion → Portfolio Decision → Ranking
 * 4) Apply Market Analysis allocation multiplier / entry gates
 * 5) Capital Allocation
 * 6) BUY generation + Exit Strategy on holdings → SELL
 */
class RecommendationEngine
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
    public function generate(PortfolioProfile $profile, ?EvaluationRun $evaluationRun = null): array
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
        $created = [];

        DB::transaction(function () use (
            $profile,
            $results,
            $heldQty,
            $allocationByStock,
            $buyMin,
            $increaseMin,
            $watchMin,
            $sellMax,
            $reduceMax,
            $veryStrongHigh,
            $veryStrongLow,
            $expiresAt,
            $defaultPct,
            $maxPct,
            $allocationBand,
            $portfolioValue,
            $riskCfg,
            $cashSummary,
            $availableCash,
            $config,
            $strategyVersion,
            $allowIncrease,
            $allowReduce,
            $maxConcurrent,
            $maxNewPositions,
            $allocCfg,
            $eligibility,
            $eligibleSet,
            $eligibilityRestricted,
            $market,
            $marketAllowsEntry,
            &$created,
        ) {
            TradingRecommendation::query()
                ->where('profile_id', $profile->id)
                ->whereIn('status', [
                    TradingRecommendation::STATUS_PENDING_REVIEW,
                    TradingRecommendation::STATUS_DEFERRED,
                    TradingRecommendation::STATUS_PUBLISHED,
                    'active',
                ])
                ->update(['status' => TradingRecommendation::STATUS_CANCELLED]);

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

            // Tie-break / sort for allocation
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

            $allocations = $this->allocator->allocate($availableCash, $buyDrafts);

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
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendations generated', [
            'profile_id' => $profile->id,
            'count' => count($created),
            'evaluation_run_id' => $evaluationRun->id,
            'strategy_version_id' => $strategyVersion->id,
            'available_investable_cash' => $availableCash,
        ]);

        return [
            'recommendations' => $created,
            'batch_id' => 'eval-'.$evaluationRun->id.'-'.now()->format('YmdHis'),
            'cash' => $cashSummary,
            'strategy' => [
                'version_id' => $strategyVersion->id,
                'version' => $strategyVersion->version,
                'name' => $strategy?->name ?? 'Strategy',
            ],
        ];
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

    public function recordReview(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        string $decision,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        $decision = TradingRecommendation::normalizeReviewDecision($decision);
        if (! in_array($decision, [
            TradingRecommendation::DECISION_APPROVED,
            TradingRecommendation::STATUS_REJECTED,
            TradingRecommendation::STATUS_DEFERRED,
        ], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be approved (or accepted), rejected, or deferred.'],
            ]);
        }

        if ($recommendation->isInformational()) {
            throw ValidationException::withMessages([
                'decision' => ['HOLD_POSITION and WATCH are informational and do not require review.'],
            ]);
        }

        if (! $recommendation->isActionable()) {
            throw ValidationException::withMessages([
                'decision' => ['Only actionable portfolio decisions can be reviewed.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'decision' => ['Executed recommendations cannot be reviewed.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_REJECTED
            && $decision !== TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'decision' => ['Rejected recommendations cannot be changed this way. Use Reopen for review first.'],
            ]);
        }

        if (! $recommendation->canBeReviewed()
            && $recommendation->status !== TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'decision' => ['Recommendation is not open for review (status: '.$recommendation->status.').'],
            ]);
        }

        $status = $decision === TradingRecommendation::DECISION_APPROVED
            ? TradingRecommendation::STATUS_PENDING_EXECUTION
            : $decision;

        DB::transaction(function () use ($recommendation, $user, $decision, $notes, $status, $profile) {
            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $fill = ['status' => $status];
            if ($status === TradingRecommendation::STATUS_PENDING_EXECUTION) {
                $fill['approved_at'] = now();
                $fill['cancelled_at'] = null;
                $fill['cancellation_reason'] = null;
                $fill['executed_at'] = null;
                $fill['executed_transaction_id'] = null;
            }

            $recommendation->forceFill($fill)->save();

            if ($status === TradingRecommendation::STATUS_PENDING_EXECUTION
                && $recommendation->requiresCashReservation()) {
                $recommendation->setRelation('profile', $profile);
                $this->reserveForApproval($recommendation);
            }
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation reviewed', [
            'recommendation_id' => $recommendation->id,
            'decision' => $decision,
            'status' => $status,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Reserve suggested investable cash when approving a buy recommendation.
     */
    public function reserveForApproval(TradingRecommendation $r): void
    {
        if (! $r->requiresCashReservation()) {
            return;
        }

        $amount = $r->suggestedInvestmentAmount();
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages([
                'cash' => ['Cannot reserve cash: recommendation has no suggested investment amount.'],
            ]);
        }

        $amount = round((float) $amount, 4);
        $profile = $r->relationLoaded('profile') && $r->profile
            ? $r->profile
            : PortfolioProfile::query()->findOrFail($r->profile_id);

        $available = $this->cash->availableInvestableCash($profile);
        if ($amount > $available + 0.0001) {
            throw ValidationException::withMessages([
                'cash' => [
                    'Insufficient available investable cash to approve this recommendation '
                    .'(need '.$amount.', available '.$available.').',
                ],
            ]);
        }

        $r->forceFill([
            'reserved_amount' => $amount,
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
            'reserved_at' => now(),
        ])->save();
    }

    /**
     * Release cash reserved for a pending-execution buy recommendation.
     */
    public function releaseReservation(TradingRecommendation $r): void
    {
        $r->forceFill([
            'reservation_status' => TradingRecommendation::RESERVATION_RELEASED,
            'reserved_amount' => 0,
            'reserved_at' => null,
        ])->save();
    }

    /**
     * Convert a reservation into an actual executed outflow amount.
     */
    public function convertReservation(TradingRecommendation $r, float $executedAmount): void
    {
        $r->forceFill([
            'reservation_status' => TradingRecommendation::RESERVATION_CONVERTED,
            'executed_amount' => round($executedAmount, 4),
            'reserved_amount' => 0,
        ])->save();
    }

    /**
     * Cancel pending execution (approved recommendation will not be traded in-system).
     */
    public function cancelExecution(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        ?string $reason = null,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        if (! $recommendation->canCancelExecution()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Only recommendations pending execution can be cancelled.'],
            ]);
        }

        $reason = $reason ? strtolower(trim($reason)) : 'other';
        if (! in_array($reason, TradingRecommendation::CANCELLATION_REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => ['Invalid cancellation reason.'],
            ]);
        }

        $label = TradingRecommendation::CANCELLATION_REASON_LABELS[$reason] ?? $reason;
        $auditNotes = trim(($notes ? $notes.' — ' : '').$label);

        DB::transaction(function () use ($recommendation, $user, $reason, $auditNotes, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => TradingRecommendation::DECISION_EXECUTION_CANCELLED,
                'notes' => $auditNotes !== '' ? $auditNotes : null,
                'created_at' => now(),
            ]);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->releaseReservation($recommendation);
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation execution cancelled', [
            'recommendation_id' => $recommendation->id,
            'reason' => $reason,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Manual expire (automatic expiry reserved for later).
     */
    public function markExpired(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        if (! $recommendation->isActionable()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Only actionable recommendations can expire.'],
            ]);
        }

        if (in_array($recommendation->status, [
            TradingRecommendation::STATUS_EXECUTED,
            TradingRecommendation::STATUS_EXPIRED,
            TradingRecommendation::STATUS_ARCHIVED,
        ], true)) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation cannot be expired from status '.$recommendation->status.'.'],
            ]);
        }

        DB::transaction(function () use ($recommendation, $user, $notes, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => TradingRecommendation::DECISION_EXPIRED,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_EXPIRED,
                'expires_at' => $recommendation->expires_at ?? now(),
            ])->save();

            $this->releaseReservation($recommendation);
        });

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Undo Approve / Reject / Defer / Cancelled → pending_review.
     * Executed recommendations: delete the linked transaction first (returns to pending_execution).
     */
    public function reopenForReview(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        if (! $recommendation->isActionable()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Only actionable recommendations can be reopened.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'recommendation' => ['Executed recommendations cannot be reopened here. Delete the linked transaction on the Transactions page first.'],
            ]);
        }

        if (! $recommendation->canReopenForReview()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation cannot be reopened (status: '.$recommendation->status.').'],
            ]);
        }

        $fromStatus = $recommendation->status;

        DB::transaction(function () use ($recommendation, $user, $notes, $fromStatus, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => TradingRecommendation::DECISION_REOPENED,
                'notes' => $notes ?? 'Reopened from '.$fromStatus.' to pending_review',
                'created_at' => now(),
            ]);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
                'approved_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'executed_at' => null,
                'executed_transaction_id' => null,
            ])->save();

            $this->releaseReservation($recommendation);
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation reopened for review', [
            'recommendation_id' => $recommendation->id,
            'from_status' => $fromStatus,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews', 'orders']);
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

    public function expireStale(PortfolioProfile $profile): int
    {
        return TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PUBLISHED,
                'active',
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => TradingRecommendation::STATUS_EXPIRED]);
    }

    /**
     * @param  list<string>|null  $statuses
     * @param  list<string>|null  $types
     * @return list<TradingRecommendation>
     */
    public function listForProfile(
        PortfolioProfile $profile,
        ?array $statuses = null,
        int $limit = 100,
        ?array $types = null,
    ): array {
        $this->expireStale($profile);

        $query = TradingRecommendation::query()
            ->with(['security', 'evaluationResult', 'reviews'])
            ->where('profile_id', $profile->id);

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($types !== null && $types !== []) {
            $upper = array_map('strtoupper', $types);
            $query->where(function ($q) use ($upper) {
                foreach ($upper as $i => $t) {
                    $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                    $q->{$method}('UPPER(recommendation_type) = ?', [$t]);
                }
            });
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function listOpenForReview(PortfolioProfile $profile): array
    {
        return $this->listForProfile(
            $profile,
            [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
            ],
            100,
            [...TradingRecommendation::ACTIONABLE_ACTIONS, 'BUY', 'SELL'],
        );
    }

    /**
     * Approved recommendations awaiting a ledger fill (Transactions → Pending Execution).
     *
     * @return list<TradingRecommendation>
     */
    public function listPendingExecution(PortfolioProfile $profile, int $limit = 100): array
    {
        return $this->listForProfile(
            $profile,
            [
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ],
            $limit,
            [...TradingRecommendation::ACTIONABLE_ACTIONS, 'BUY', 'SELL'],
        );
    }

    /** @deprecated use listOpenForReview */
    public function listActive(PortfolioProfile $profile): array
    {
        return $this->listOpenForReview($profile);
    }

    public function findForProfile(PortfolioProfile $profile, int $id): ?TradingRecommendation
    {
        return TradingRecommendation::query()
            ->with([
                'security',
                'evaluationResult.candidate',
                'reviews.user',
                'orders',
                'executedTransaction.stock',
            ])
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function history(PortfolioProfile $profile, int $limit = 50): array
    {
        return $this->listForProfile($profile, null, $limit);
    }

    /**
     * @return list<RecommendationReview>
     */
    public function reviewHistory(TradingRecommendation $recommendation): array
    {
        return RecommendationReview::query()
            ->with('user')
            ->where('recommendation_id', $recommendation->id)
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
