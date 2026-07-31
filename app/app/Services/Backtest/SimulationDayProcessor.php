<?php

namespace App\Services\Backtest;

use App\Engines\Recommendation\Allocation\ScorePriorityCapitalAllocator;
use App\Engines\Strategy\ExitStrategyEvaluator;
use App\Models\BacktestRun;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;

/**
 * Path-dependent single trading-day simulation:
 * eligibility → score → recommend → execute virtual trades → snapshot.
 */
class SimulationDayProcessor
{
    protected ScorePriorityCapitalAllocator $allocator;

    public function __construct(
        protected AsOfFactorScorer $scorer,
        protected EligibilityPrecomputeService $eligibility,
        protected StrategyConfigurationService $strategies,
        ?ScorePriorityCapitalAllocator $allocator = null,
    ) {
        $this->allocator = $allocator ?? new ScorePriorityCapitalAllocator();
    }

    /**
     * @return array{
     *     transactions: list<array<string, mixed>>,
     *     closed_trades: list<array<string, mixed>>,
     *     snapshot: array<string, mixed>
     * }
     */
    public function processDay(BacktestRun $run, SimulationContext $ctx, string $asOfDate): array
    {
        $config = is_array($ctx->get('config_snapshot')) ? $ctx->get('config_snapshot') : [];
        $portfolio = new PaperPortfolioManager($ctx);
        $executor = new PaperTradeExecutor($ctx);

        $valuation = $portfolio->valueAsOf($asOfDate, false);
        $heldQty = $portfolio->heldQuantities();
        $eligibleIds = $this->eligibility->entryHitsForDate($run, $asOfDate);
        $eligibilityRestricted = (bool) ($ctx->get('eligibility_restricted', true));
        $eligibleSet = array_fill_keys($eligibleIds, true);
        $exitByScreener = $this->eligibility->exitHitsByScreenerForDate($run, $asOfDate);

        $candidateIds = array_values(array_unique(array_merge(
            array_keys($heldQty),
            $eligibleIds
        )));

        $thresholds = $config[TradingOsConfig::STRATEGY_THRESHOLDS] ?? [];
        $buyMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_OPEN_POSITION] ?? TradingOsConfig::recommendationBuyScoreMin());
        $increaseMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_INCREASE_POSITION] ?? $buyMin);
        $watchMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_WATCH] ?? TradingOsConfig::recommendationWatchScoreMin());
        $sellMax = (float) ($thresholds[TradingOsConfig::THRESHOLD_EXIT_POSITION] ?? TradingOsConfig::recommendationSellScoreMax());
        $reduceMax = (float) ($thresholds[TradingOsConfig::THRESHOLD_REDUCE_POSITION] ?? $sellMax);
        $veryStrongHigh = (float) ($thresholds[TradingOsConfig::THRESHOLD_VERY_STRONG_HIGH] ?? TradingOsConfig::recommendationVeryStrongHigh());
        $veryStrongLow = (float) ($thresholds[TradingOsConfig::THRESHOLD_VERY_STRONG_LOW] ?? TradingOsConfig::recommendationVeryStrongLow());

        $behaviour = $config[TradingOsConfig::STRATEGY_RECOMMENDATION_BEHAVIOUR] ?? [];
        $allowIncrease = (bool) ($behaviour['allow_increase_position'] ?? true);
        $allowReduce = (bool) ($behaviour['allow_reduce_position'] ?? true);
        $maxConcurrent = (int) ($behaviour['max_concurrent_recommendations'] ?? TradingOsConfig::recommendationMaxConcurrent());

        $portfolioRules = $config[TradingOsConfig::STRATEGY_PORTFOLIO_RULES] ?? [];
        $defaultPct = (float) ($portfolioRules['default_position_size_pct'] ?? TradingOsConfig::recommendationDefaultPositionPct());
        $maxPct = (float) ($portfolioRules['max_position_size_pct'] ?? TradingOsConfig::recommendationMaxPositionPct());
        $allocationBand = (float) ($portfolioRules['allocation_band_pct'] ?? TradingOsConfig::recommendationAllocationBandPct());
        $maxNewPositions = (int) ($portfolioRules['max_new_positions_per_cycle'] ?? TradingOsConfig::recommendationMaxNewPositionsPerCycle());
        $minCashReservePct = (float) ($portfolioRules['min_cash_reserve_pct'] ?? 0);
        $maxCashDeployPct = (float) ($portfolioRules['max_cash_deployment_pct'] ?? 100);

        $riskCfg = $config[TradingOsConfig::STRATEGY_RISK] ?? [];
        $allocCfg = $config[TradingOsConfig::STRATEGY_CAPITAL_ALLOCATION] ?? [];

        // Simulation: market gates disabled (no historical market analytics series yet).
        $marketAllowsEntry = true;

        $availableCash = $ctx->cash();
        if ($minCashReservePct > 0) {
            $availableCash = max(0.0, $availableCash - round($ctx->cash() * ($minCashReservePct / 100.0), 4));
        }
        if ($maxCashDeployPct < 100) {
            $availableCash = min($availableCash, round($ctx->cash() * ($maxCashDeployPct / 100.0), 4));
        }

        $portfolioValue = (float) $valuation['portfolio_value'];
        $symbols = $this->loadSymbols($candidateIds);
        $drafts = [];

        foreach ($candidateIds as $stockId) {
            $qtyHeld = (float) ($heldQty[$stockId] ?? 0);
            $isHeld = $qtyHeld > 0;
            $isEligible = ! $eligibilityRestricted || isset($eligibleSet[$stockId]);
            if (! $isEligible && ! $isHeld) {
                continue;
            }

            $eval = $this->scorer->score($stockId, $asOfDate);
            if (! empty($eval['skipped']) && ! $isHeld) {
                continue;
            }

            $factorScores = is_array($eval['factor_scores'] ?? null) ? $eval['factor_scores'] : [];
            $scored = $this->strategies->score($factorScores, $config);
            $score = (float) $scored['overall_score'];
            $confidence = (float) ($eval['confidence'] ?? 0.5);
            $price = (float) ($eval['indicators']['close'] ?? ($valuation['prices'][$stockId] ?? 0));
            if ($price <= 0) {
                continue;
            }

            $currentAlloc = $portfolio->allocationPct($stockId, $portfolioValue, $price);
            $avgCost = (float) (($ctx->holdings()[(string) $stockId]['avg_cost'] ?? 0));
            $unrealizedPct = ($isHeld && $avgCost > 0)
                ? round((($price - $avgCost) / $avgCost) * 100.0, 4)
                : null;

            $bandPct = $this->strategies->allocationPctForScore($score, $config);
            $targetAlloc = min($maxPct, $bandPct > 0 ? $bandPct : $defaultPct);

            $exitEval = ['triggered' => false, 'matched' => [], 'status' => 'Not Triggered'];
            if ($isHeld) {
                $exitEval = ExitStrategyEvaluator::evaluate(
                    is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [],
                    [
                        'security_id' => $stockId,
                        'overall_score' => $score,
                        'indicator_scores' => $factorScores,
                        'indicators' => $eval['indicators'] ?? [],
                        'unrealized_pnl_pct' => $unrealizedPct,
                        'exit_screener_hits_by_screener' => $exitByScreener,
                    ]
                );
            }

            $opinion = $this->buildOpinion($score, $confidence, $buyMin, $watchMin, $sellMax, $veryStrongHigh, $veryStrongLow);
            $atrPct = $eval['indicators']['atr_pct'] ?? null;
            $risk = $this->riskLevel($atrPct, is_array($riskCfg) ? $riskCfg : []);
            $action = $this->decideAction(
                $opinion, $isHeld, $currentAlloc, $targetAlloc, $allocationBand, $risk,
                $score, $buyMin, $increaseMin, $sellMax, $reduceMax, $allowIncrease, $allowReduce
            );

            if ($isHeld && ($exitEval['triggered'] ?? false)) {
                $action = TradingRecommendation::ACTION_EXIT_POSITION;
            }
            if ($isHeld && ! $isEligible && ! ($exitEval['triggered'] ?? false)) {
                $action = TradingRecommendation::ACTION_HOLD_POSITION;
            }
            if (! $isEligible && in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                $action = $isHeld ? TradingRecommendation::ACTION_HOLD_POSITION : TradingRecommendation::ACTION_WATCH;
            }
            if (! $marketAllowsEntry && in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                $action = $isHeld ? TradingRecommendation::ACTION_HOLD_POSITION : TradingRecommendation::ACTION_WATCH;
            }

            $plan = null;
            if (in_array($action, TradingRecommendation::ACTIONABLE_ACTIONS, true)) {
                $plan = $this->buildPlan($action, $portfolioValue, $currentAlloc, $targetAlloc, $maxPct, $qtyHeld, $price, $risk);
            }

            $drafts[] = [
                'key' => $stockId,
                'security_id' => $stockId,
                'symbol' => $symbols[$stockId] ?? (string) $stockId,
                'score' => $score,
                'confidence' => $confidence,
                'qty_held' => $qtyHeld,
                'is_held' => $isHeld,
                'reference_price' => $price,
                'current_alloc' => $currentAlloc,
                'target_alloc' => $targetAlloc,
                'action' => $action,
                'plan' => $plan,
                'position_size' => $plan['suggested_investment_amount'] ?? null,
                'priority' => (int) max(1, min(100, round($score))),
                'exit_eval' => $exitEval,
                'factor_scores' => $factorScores,
            ];
        }

        // Screener-exit-only holdings not already drafted.
        $drafts = $this->appendScreenerExitDrafts($drafts, $heldQty, $exitByScreener, $config, $valuation, $portfolioValue, $maxPct, $symbols, $ctx);

        usort($drafts, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['confidence'] <=> $a['confidence']));

        $buyDrafts = [];
        $maxPositionAmount = $portfolioValue > 0 ? round($portfolioValue * ($maxPct / 100.0), 4) : null;
        $newOpenCount = 0;
        foreach ($drafts as $draft) {
            if (! in_array($draft['action'], [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                continue;
            }
            if ($draft['action'] === TradingRecommendation::ACTION_OPEN_POSITION) {
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
        $allocations = $this->allocator->allocate($availableCash, $buyDrafts);

        $transactions = [];
        $closedTrades = [];
        $executed = 0;

        // Exits / reduces first (free cash), then buys.
        foreach ($drafts as $draft) {
            if ($executed >= $maxConcurrent) {
                break;
            }
            $action = $draft['action'];
            if (! in_array($action, [TradingRecommendation::ACTION_EXIT_POSITION, TradingRecommendation::ACTION_REDUCE_POSITION], true)) {
                continue;
            }
            $qty = (int) ($draft['plan']['suggested_quantity'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $reason = ($draft['exit_eval']['triggered'] ?? false) ? 'exit_strategy' : 'recommendation';
            $result = $executor->sell(
                $asOfDate,
                (int) $draft['security_id'],
                (string) $draft['symbol'],
                (float) $qty,
                (float) $draft['reference_price'],
                $reason,
                $action,
            );
            if ($result['ok'] ?? false) {
                $transactions[] = $result['transaction'];
                foreach ($result['closed_trades'] ?? [] as $t) {
                    $closedTrades[] = $t;
                }
                $executed++;
            }
        }

        // Refresh available cash after sells.
        $availableCash = $ctx->cash();
        if ($minCashReservePct > 0) {
            $availableCash = max(0.0, $availableCash - round($ctx->cash() * ($minCashReservePct / 100.0), 4));
        }

        foreach ($drafts as $draft) {
            if ($executed >= $maxConcurrent) {
                break;
            }
            $action = $draft['action'];
            if (! in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                continue;
            }
            $alloc = $allocations[$draft['key']] ?? ['allocated_amount' => 0.0, 'quantity' => 0];
            $qty = (int) ($alloc['quantity'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $result = $executor->buy(
                $asOfDate,
                (int) $draft['security_id'],
                (string) $draft['symbol'],
                (float) $qty,
                (float) $draft['reference_price'],
                'recommendation',
                $action,
            );
            if ($result['ok'] ?? false) {
                $transactions[] = $result['transaction'];
                $executed++;
            }
        }

        $valuation = $portfolio->valueAsOf($asOfDate, true);

        return [
            'transactions' => $transactions,
            'closed_trades' => $closedTrades,
            'snapshot' => $this->buildSnapshot($ctx, $asOfDate, $valuation),
        ];
    }

    /**
     * @param  array{cash: float, invested_value: float, portfolio_value: float, unrealized_profit: float, holdings_count: int, drawdown_pct: float}  $valuation
     * @return array<string, mixed>
     */
    private function buildSnapshot(SimulationContext $ctx, string $asOfDate, array $valuation): array
    {
        return [
            'snapshot_date' => $asOfDate,
            'cash' => $valuation['cash'],
            'invested_value' => $valuation['invested_value'],
            'portfolio_value' => $valuation['portfolio_value'],
            'realized_profit' => round((float) $ctx->get('realized_profit', 0), 4),
            'unrealized_profit' => $valuation['unrealized_profit'],
            'drawdown_pct' => $valuation['drawdown_pct'],
            'holdings_count' => $valuation['holdings_count'],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function loadSymbols(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Stock::query()->whereIn('id', $ids)->pluck('symbol', 'id')
            ->mapWithKeys(fn ($sym, $id) => [(int) $id => (string) $sym])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<int, float>  $heldQty
     * @param  array<int, list<int>>  $exitByScreener
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $valuation
     * @param  array<int, string>  $symbols
     * @return list<array<string, mixed>>
     */
    private function appendScreenerExitDrafts(
        array $drafts,
        array $heldQty,
        array $exitByScreener,
        array $config,
        array $valuation,
        float $portfolioValue,
        float $maxPct,
        array $symbols,
        SimulationContext $ctx,
    ): array {
        $exitConfig = is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [];
        if (! ($exitConfig['enabled'] ?? true)) {
            return $drafts;
        }
        $screenerRules = array_values(array_filter(
            is_array($exitConfig['rules'] ?? null) ? $exitConfig['rules'] : [],
            static fn ($rule) => is_array($rule)
                && ($rule['key'] ?? '') === 'screener_exit'
                && ($rule['enabled'] ?? false)
                && (int) ($rule['screener_id'] ?? 0) > 0
        ));
        if ($screenerRules === []) {
            return $drafts;
        }

        $processed = [];
        foreach ($drafts as $d) {
            $processed[(int) $d['security_id']] = true;
        }

        foreach ($heldQty as $stockId => $qty) {
            $securityId = (int) $stockId;
            if ($securityId < 1 || $qty <= 0 || isset($processed[$securityId])) {
                continue;
            }
            $exitEval = ExitStrategyEvaluator::evaluate(
                ['enabled' => true, 'mode' => 'any', 'rules' => $screenerRules],
                [
                    'security_id' => $securityId,
                    'exit_screener_hits_by_screener' => $exitByScreener,
                ]
            );
            if (! ($exitEval['triggered'] ?? false)) {
                continue;
            }
            $price = (float) ($valuation['prices'][$securityId] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $currentAlloc = $portfolioValue > 0
                ? round((((float) $qty) * $price / $portfolioValue) * 100.0, 4)
                : 0.0;
            $plan = $this->buildPlan(
                TradingRecommendation::ACTION_EXIT_POSITION,
                $portfolioValue,
                $currentAlloc,
                0.0,
                $maxPct,
                (float) $qty,
                $price,
                TradingRecommendation::RISK_MEDIUM,
            );
            $drafts[] = [
                'key' => $securityId,
                'security_id' => $securityId,
                'symbol' => $symbols[$securityId] ?? (string) ($ctx->holdings()[(string) $securityId]['symbol'] ?? $securityId),
                'score' => 0.0,
                'confidence' => 0.7,
                'qty_held' => (float) $qty,
                'is_held' => true,
                'reference_price' => $price,
                'current_alloc' => $currentAlloc,
                'target_alloc' => 0.0,
                'action' => TradingRecommendation::ACTION_EXIT_POSITION,
                'plan' => $plan,
                'position_size' => null,
                'priority' => 90,
                'exit_eval' => $exitEval,
                'factor_scores' => [],
            ];
            $processed[$securityId] = true;
        }

        return $drafts;
    }

    private function buildOpinion(
        float $score,
        float $confidence,
        float $buyMin,
        float $watchMin,
        float $sellMax,
        float $veryStrongHigh,
        float $veryStrongLow,
    ): array {
        if ($score >= $buyMin) {
            $direction = TradingRecommendation::OPINION_BULLISH;
        } elseif ($score <= $sellMax) {
            $direction = TradingRecommendation::OPINION_BEARISH;
        } else {
            $direction = TradingRecommendation::OPINION_NEUTRAL;
        }
        $strength = match (true) {
            $score >= $veryStrongHigh || $score <= $veryStrongLow => TradingRecommendation::STRENGTH_VERY_STRONG,
            $score >= $buyMin || $score <= $sellMax => TradingRecommendation::STRENGTH_STRONG,
            $score >= $watchMin || $score <= ($sellMax + 10) => TradingRecommendation::STRENGTH_MODERATE,
            default => TradingRecommendation::STRENGTH_WEAK,
        };

        return [
            'direction' => $direction,
            'strength' => $strength,
            'confidence' => round($confidence, 4),
            'score' => $score,
        ];
    }

    private function decideAction(
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
        $strongBull = $direction === TradingRecommendation::OPINION_BULLISH
            && in_array($strength, [TradingRecommendation::STRENGTH_STRONG, TradingRecommendation::STRENGTH_VERY_STRONG], true);
        $strongBear = $direction === TradingRecommendation::OPINION_BEARISH
            && in_array($strength, [TradingRecommendation::STRENGTH_STRONG, TradingRecommendation::STRENGTH_VERY_STRONG], true);
        $overTarget = $currentAlloc > ($targetAlloc + $band);
        $underTarget = $currentAlloc < ($targetAlloc - $band);
        $highRisk = $risk === TradingRecommendation::RISK_HIGH;

        if (! $isHeld) {
            if ($strongBull || $score >= $buyMin) {
                return TradingRecommendation::ACTION_OPEN_POSITION;
            }

            return TradingRecommendation::ACTION_WATCH;
        }
        if ($strongBear || $score <= $sellMax) {
            return TradingRecommendation::ACTION_EXIT_POSITION;
        }
        if ($allowReduce && ($direction === TradingRecommendation::OPINION_BEARISH || $score <= $reduceMax || ($highRisk && $overTarget))) {
            return TradingRecommendation::ACTION_REDUCE_POSITION;
        }
        if ($allowReduce && $overTarget && ($direction !== TradingRecommendation::OPINION_BULLISH || $highRisk)) {
            return TradingRecommendation::ACTION_REDUCE_POSITION;
        }
        if ($allowIncrease && $direction === TradingRecommendation::OPINION_BULLISH && $underTarget && $score >= $increaseMin) {
            return TradingRecommendation::ACTION_INCREASE_POSITION;
        }

        return TradingRecommendation::ACTION_HOLD_POSITION;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(
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
            'risk' => $risk,
        ];

        if ($action === TradingRecommendation::ACTION_OPEN_POSITION || $action === TradingRecommendation::ACTION_INCREASE_POSITION) {
            $suggestedAlloc = $action === TradingRecommendation::ACTION_OPEN_POSITION
                ? $capAlloc
                : min($maxPct, max($currentAlloc, $capAlloc));
            $gapPct = max(0, $suggestedAlloc - $currentAlloc);
            $amount = $portfolioValue > 0 ? round($portfolioValue * ($gapPct / 100.0), 2) : null;
            $qty = ($amount !== null && $price) ? (int) max(0, (int) round($amount / $price)) : null;
            if ($qty !== null && $price && $qty > 0) {
                $amount = round($qty * $price, 2);
            }
            $plan['suggested_allocation_pct'] = round($suggestedAlloc, 4);
            $plan['suggested_investment_amount'] = $amount;
            $plan['suggested_quantity'] = $qty;
            $plan['side'] = 'buy';
        } elseif ($action === TradingRecommendation::ACTION_REDUCE_POSITION) {
            $suggestedAlloc = max(0, min($currentAlloc, $capAlloc));
            if ($suggestedAlloc >= $currentAlloc - 0.01) {
                $suggestedAlloc = round($currentAlloc * 0.7, 4);
            }
            $sellFraction = $currentAlloc > 0 ? max(0, ($currentAlloc - $suggestedAlloc) / $currentAlloc) : 0;
            $sharesToSell = (int) max(0, (int) round($qtyHeld * $sellFraction));
            $heldWhole = (int) max(0, (int) round($qtyHeld));
            if ($sharesToSell > $heldWhole) {
                $sharesToSell = $heldWhole;
            }
            $plan['suggested_allocation_pct'] = round($suggestedAlloc, 4);
            $plan['suggested_quantity'] = $sharesToSell;
            $plan['side'] = 'sell';
        } elseif ($action === TradingRecommendation::ACTION_EXIT_POSITION) {
            $heldWhole = (int) max(0, (int) round($qtyHeld));
            $plan['suggested_allocation_pct'] = 0.0;
            $plan['suggested_quantity'] = $heldWhole;
            $plan['side'] = 'sell';
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $riskCfg
     */
    private function riskLevel(mixed $atrPct, array $riskCfg): string
    {
        if (! is_numeric($atrPct)) {
            return TradingRecommendation::RISK_MEDIUM;
        }
        $v = (float) $atrPct;
        if ($v >= (float) ($riskCfg['high_atr_pct'] ?? TradingOsConfig::recommendationRiskHighAtrPct())) {
            return TradingRecommendation::RISK_HIGH;
        }
        if ($v >= (float) ($riskCfg['medium_atr_pct'] ?? TradingOsConfig::recommendationRiskMediumAtrPct())) {
            return TradingRecommendation::RISK_MEDIUM;
        }

        return TradingRecommendation::RISK_LOW;
    }
}
