<?php

namespace App\Engines\Recommendation;

use App\Engines\Recommendation\Allocation\CapitalAllocationStrategy;
use App\Engines\Recommendation\Allocation\ReturnQualityCapitalAllocator;
use App\Engines\Strategy\ExitStrategyEvaluator;
use App\Exceptions\DomainException;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\CashManagementService;
use App\Services\DataQualityGuardService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\ProfileSettingsService;
use App\Services\Ranking\CapitalFillOrderService;
use App\Services\Ranking\ReturnQualityRankingService;
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\Entry\BuyCooldownEvaluator;
use App\Services\Entry\MinimumActionableAmountResolver;
use App\Services\Entry\StaggeredEntryCalculator;
use App\Services\Entry\StrategyPositionTargetService;
use App\Services\Entry\WholeShareQuantityCalculator;
use App\Services\Risk\ExitAttribution;
use App\Services\Risk\ExitPrecedenceEvaluator;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TD-002: recommendation generation stages extracted out of RecommendationEngine::generate().
 *
 * V3 orchestration (per enabled strategy):
 * snapshot → prepareContext → cancelStaleRecommendations (strategy-scoped)
 * → buildDrafts → rankDrafts (return-quality or OD-23) → allocateCapital
 * → applyCapitalOutcomes → persistDrafts.
 */
class RecommendationGenerationPipeline
{
    protected CapitalAllocationStrategy $allocator;

    protected RecommendationLendingCoordinator $lending;

    protected ExitPrecedenceEvaluator $exitPrecedence;

    protected StaggeredEntryCalculator $staggeredEntry;

    protected BuyCooldownEvaluator $buyCooldown;

    protected StrategyPositionTargetService $positionTargets;

    protected WholeShareQuantityCalculator $wholeShares;

    protected MinimumActionableAmountResolver $minActionable;

    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
        protected CashManagementService $cash,
        protected StrategyConfigurationService $strategies,
        protected StrategyEligibilityService $eligibility,
        protected MarketAnalyticsService $marketAnalytics,
        protected DataQualityGuardService $dataQualityGuard,
        protected PortfolioCapitalAccountingService $capitalAccounting,
        protected ReturnQualityRankingService $returnQualityRanking,
        protected CapitalFillOrderService $capitalFillOrder,
        ?CapitalAllocationStrategy $allocator = null,
        ?RecommendationLendingCoordinator $lending = null,
        ?ExitPrecedenceEvaluator $exitPrecedence = null,
        ?StaggeredEntryCalculator $staggeredEntry = null,
        ?BuyCooldownEvaluator $buyCooldown = null,
        ?StrategyPositionTargetService $positionTargets = null,
        ?WholeShareQuantityCalculator $wholeShares = null,
        ?MinimumActionableAmountResolver $minActionable = null,
    ) {
        $this->allocator = $allocator ?? new ReturnQualityCapitalAllocator;
        $this->lending = $lending ?? app(RecommendationLendingCoordinator::class);
        $this->exitPrecedence = $exitPrecedence ?? app(ExitPrecedenceEvaluator::class);
        $this->staggeredEntry = $staggeredEntry ?? app(StaggeredEntryCalculator::class);
        $this->buyCooldown = $buyCooldown ?? app(BuyCooldownEvaluator::class);
        $this->positionTargets = $positionTargets ?? app(StrategyPositionTargetService::class);
        $this->wholeShares = $wholeShares ?? app(WholeShareQuantityCalculator::class);
        $this->minActionable = $minActionable ?? app(MinimumActionableAmountResolver::class);
    }

    /**
     * @param  list<int>|null  $onlyStrategyIds
     * @return array{
     *     recommendations: list<TradingRecommendation>,
     *     batch_id: string,
     *     cash: array{cash_balance: float, reserved_cash: float, available_investable_cash: float},
     *     strategy: array{version_id: int, version: int, name: string}|null,
     *     strategies: list<array{version_id: int, version: int, name: string, strategy_id: int}>
     * }
     */
    public function run(
        PortfolioProfile $profile,
        ?EvaluationRun $evaluationRun = null,
        ?array $onlyStrategyIds = null,
    ): array {
        $versions = $this->enabledStrategyVersions($profile);
        if ($versions === []) {
            $this->strategies->ensureActive($profile);
            $versions = $this->enabledStrategyVersions($profile);
        }
        if ($onlyStrategyIds !== null) {
            $allow = [];
            foreach ($onlyStrategyIds as $id) {
                $allow[(int) $id] = true;
            }
            $versions = array_values(array_filter(
                $versions,
                static fn (TradingStrategyVersion $version) => isset($allow[(int) $version->strategy_id]),
            ));
        }

        $snapshot = $this->capitalAccounting->snapshot($profile);
        $created = [];
        $strategySummaries = [];

        DB::transaction(function () use ($profile, $evaluationRun, $versions, $snapshot, &$created, &$strategySummaries) {
            foreach ($versions as $strategyVersion) {
                $ctx = $this->prepareContext($profile, $evaluationRun, $strategyVersion, $snapshot);
                $strategyId = (int) ($ctx['strategy']?->id ?? 0);
                $this->cancelStaleRecommendations($profile, $strategyId > 0 ? $strategyId : null);

                $drafts = $this->buildDrafts($ctx);
                $drafts = $this->rankDrafts($drafts, $ctx);
                $drafts = $this->enforceMaxHoldingsOpenCap($drafts, $ctx);
                $allocations = $this->allocateCapital($drafts, $ctx);
                $drafts = $this->applyCapitalOutcomes($drafts, $allocations);
                $created = array_merge($created, $this->persistDrafts($drafts, $ctx, $profile));

                $strategySummaries[] = [
                    'strategy_id' => $strategyId,
                    'version_id' => $ctx['strategy_version']->id,
                    'version' => $ctx['strategy_version']->version,
                    'name' => $ctx['strategy']?->name ?? 'Strategy',
                ];
            }
        });

        $primary = $strategySummaries[0] ?? null;
        $cash = [
            'cash_balance' => (float) ($snapshot['physical_cash']['total_cash'] ?? 0),
            'reserved_cash' => (float) ($snapshot['physical_cash']['pending_execution_reservations'] ?? 0),
            'available_investable_cash' => (float) ($snapshot['investable_cash_component'] ?? 0),
        ];

        $this->logger->event('RecommendationEngine', 'recommendation.generated', 'info', 'Recommendations generated', [
            'profile_id' => $profile->id,
            'count' => count($created),
            'strategy_count' => count($strategySummaries),
            'evaluation_run_id' => $evaluationRun?->id,
        ]);

        return [
            'recommendations' => $created,
            'batch_id' => 'eval-'.($evaluationRun?->id ?? 'na').'-'.now()->format('YmdHis'),
            'cash' => $cash,
            'strategy' => $primary === null ? null : [
                'version_id' => $primary['version_id'],
                'version' => $primary['version'],
                'name' => $primary['name'],
            ],
            'strategies' => $strategySummaries,
        ];
    }

    /**
     * Shared decision core for one security (F137 / PD-17).
     * Same path as generate: prepare → drafts → rank → allocate → capital demotion.
     * Does NOT cancel or persist recommendations.
     *
     * @return array{
     *     available: bool,
     *     unavailable_reasons: list<array{code: string, message: string}>,
     *     evaluation_run: ?EvaluationRun,
     *     strategy_version: ?TradingStrategyVersion,
     *     draft: ?array<string, mixed>,
     *     final_action: ?string,
     *     reasoning: ?string,
     *     eligibility: ?array<string, mixed>,
     *     gate_decision: ?array<string, mixed>
     * }
     */
    public function decideForSecurity(
        PortfolioProfile $profile,
        int $securityId,
        ?EvaluationRun $evaluationRun = null,
        ?TradingStrategyVersion $strategyVersion = null,
    ): array {
        $evaluationRun ??= EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $evaluationRun) {
            return [
                'available' => false,
                'unavailable_reasons' => [[
                    'code' => 'NO_EVALUATION_CYCLE',
                    'message' => 'No completed evaluation run available for recommendations.',
                ]],
                'evaluation_run' => null,
                'strategy_version' => $strategyVersion,
                'draft' => null,
                'final_action' => null,
                'reasoning' => null,
                'eligibility' => null,
                'gate_decision' => null,
            ];
        }

        $snapshot = $this->capitalAccounting->snapshot($profile);
        $ctx = $this->prepareContext($profile, $evaluationRun, $strategyVersion, $snapshot);
        $drafts = $this->buildDrafts($ctx);
        $drafts = $this->rankDrafts($drafts, $ctx);
        $drafts = $this->enforceMaxHoldingsOpenCap($drafts, $ctx);
        $allocations = $this->allocateCapital($drafts, $ctx);
        $drafts = $this->applyCapitalOutcomes($drafts, $allocations);

        $draft = null;
        foreach ($drafts as $candidate) {
            if ((int) ($candidate['security_id'] ?? 0) === $securityId) {
                $draft = $candidate;
                break;
            }
        }

        if ($draft === null) {
            $eligibility = $ctx['eligibility'] ?? [];
            $restricted = (bool) ($ctx['eligibility_restricted'] ?? false);
            $eligibleSet = $ctx['eligible_set'] ?? [];
            $inResults = false;
            foreach ($ctx['results'] ?? [] as $result) {
                if ((int) ($result->candidate?->security_id ?? 0) === $securityId) {
                    $inResults = true;
                    break;
                }
            }

            $reasons = [];
            if (! $inResults) {
                $reasons[] = [
                    'code' => 'NOT_IN_EVALUATION_CYCLE',
                    'message' => 'Stock has no evaluation result in the latest completed evaluation cycle.',
                ];
            } elseif ($restricted && empty($eligibleSet[$securityId])) {
                $reasons[] = [
                    'code' => 'NOT_ELIGIBLE',
                    'message' => 'Stock is not eligible under the selected strategy screener rules and is not held.',
                ];
            } else {
                $reasons[] = [
                    'code' => 'NO_RECOMMENDATION_DRAFT',
                    'message' => 'Shared decision logic produced no recommendation draft for this stock.',
                ];
            }

            return [
                'available' => false,
                'unavailable_reasons' => $reasons,
                'evaluation_run' => $evaluationRun,
                'strategy_version' => $ctx['strategy_version'],
                'draft' => null,
                'final_action' => null,
                'reasoning' => null,
                'eligibility' => $eligibility,
                'gate_decision' => $ctx['market_gate_decision'] ?? null,
            ];
        }

        $gateDecision = $ctx['market_gate_decision'] ?? MarketGateEvaluator::evaluate($ctx['market'] ?? [], []);
        $reasoning = $this->buildReasoning(
            $draft['opinion'],
            $draft['action'],
            $draft['current_alloc'],
            $draft['target_alloc'],
            $draft['is_held'],
            $draft['risk'],
            (bool) ($draft['market_gate_demoted'] ?? false),
            is_array($gateDecision['strategy_gates']['block_reasons'] ?? null)
                ? $gateDecision['strategy_gates']['block_reasons']
                : [],
        );

        return [
            'available' => true,
            'unavailable_reasons' => [],
            'evaluation_run' => $evaluationRun,
            'strategy_version' => $ctx['strategy_version'],
            'draft' => $draft,
            'final_action' => $draft['action'],
            'reasoning' => $reasoning,
            'eligibility' => $ctx['eligibility'] ?? null,
            'gate_decision' => $gateDecision,
        ];
    }

    /**
     * Resolve evaluation run, strategy config, thresholds, market gates/multipliers,
     * cash headroom, eligibility, and current holdings snapshot.
     *
     * When $strategyVersion is provided (F137 / per-strategy generate), do NOT call ensureActive / seed.
     *
     * @param  array<string, mixed>|null  $capitalSnapshot  WS2 snapshot; when null, one snapshot is taken here.
     * @return array<string, mixed>
     */
    protected function prepareContext(
        PortfolioProfile $profile,
        ?EvaluationRun $evaluationRun = null,
        ?TradingStrategyVersion $strategyVersion = null,
        ?array $capitalSnapshot = null,
    ): array {
        $evaluationRun ??= EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $evaluationRun) {
            throw new DomainException(
                'No completed evaluation run available for recommendations.',
                'RECOMMENDATION_PRECONDITION',
            );
        }

        if ($strategyVersion === null) {
            $strategyVersion = $this->strategies->ensureActive($profile);
        } else {
            $strategyVersion->loadMissing('strategy');
        }
        $strategy = $strategyVersion->strategy;
        $config = $strategyVersion->config_json ?? $this->strategies->defaultConfig();

        $thresholds = $config[TradingOsConfig::STRATEGY_THRESHOLDS] ?? [];
        $buyMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_OPEN_POSITION] ?? TradingOsConfig::recommendationBuyScoreMin());
        $increaseMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_INCREASE_POSITION] ?? $buyMin);
        $watchMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_WATCH] ?? TradingOsConfig::recommendationWatchScoreMin());
        $sellMax = (float) ($thresholds[TradingOsConfig::THRESHOLD_EXIT_POSITION] ?? TradingOsConfig::recommendationSellScoreMax());
        $reduceMax = (float) ($thresholds[TradingOsConfig::THRESHOLD_REDUCE_POSITION] ?? $sellMax);
        $veryStrongHigh = (float) ($thresholds[TradingOsConfig::THRESHOLD_VERY_STRONG_HIGH] ?? TradingOsConfig::recommendationVeryStrongHigh());
        $veryStrongLow = (float) ($thresholds[TradingOsConfig::THRESHOLD_VERY_STRONG_LOW] ?? TradingOsConfig::recommendationVeryStrongLow());

        $behaviour = $config[TradingOsConfig::STRATEGY_RECOMMENDATION_BEHAVIOUR] ?? [];
        $expiryHours = (int) ($behaviour['expiry_hours'] ?? TradingOsConfig::recommendationExpiryHours());
        $allowIncrease = (bool) ($behaviour['allow_increase_position'] ?? true);
        $allowReduce = (bool) ($behaviour['allow_reduce_position'] ?? true);
        $maxConcurrent = (int) ($behaviour['max_concurrent_recommendations'] ?? TradingOsConfig::recommendationMaxConcurrent());

        $portfolioRules = $config[TradingOsConfig::STRATEGY_PORTFOLIO_RULES] ?? [];
        $defaultPct = (float) ($portfolioRules['default_position_size_pct'] ?? TradingOsConfig::recommendationDefaultPositionPct());
        $maxPct = (float) ($portfolioRules['max_position_size_pct'] ?? TradingOsConfig::recommendationMaxPositionPct());
        $allocationBand = (float) ($portfolioRules['allocation_band_pct'] ?? TradingOsConfig::recommendationAllocationBandPct());
        $maxNewPositions = (int) ($portfolioRules['max_new_positions_per_cycle'] ?? TradingOsConfig::recommendationMaxNewPositionsPerCycle());
        $maxHoldings = isset($portfolioRules['max_holdings']) && is_numeric($portfolioRules['max_holdings'])
            ? (int) $portfolioRules['max_holdings']
            : 0;
        if ($maxHoldings < 1) {
            $maxHoldings = 0;
        }
        // V3 §3.5 — diversification default: single name ≤ 1/max_holdings unless tighter max_position_size_pct.
        if ($maxHoldings > 0) {
            $maxPct = min($maxPct, 100.0 / $maxHoldings);
        }
        // V3 §3.5 — portfolio ceiling: tighter of strategy vs portfolio_max_position_pct wins.
        $portfolioMaxRaw = app(ProfileSettingsService::class)
            ->get($profile, 'portfolio_max_position_pct', '');
        if ($portfolioMaxRaw !== null && $portfolioMaxRaw !== '' && is_numeric($portfolioMaxRaw)) {
            $portfolioMaxPct = (float) $portfolioMaxRaw;
            if ($portfolioMaxPct > 0) {
                $maxPct = min($maxPct, $portfolioMaxPct);
            }
        }

        // SD-032 / F098: consume Market Analysis Engine — never recalculate market metrics here.
        $market = [];
        try {
            $market = $this->marketAnalytics->latest();
        } catch (Throwable) {
            $market = [];
        }
        $marketGates = is_array($config[TradingOsConfig::STRATEGY_MARKET_GATES] ?? null)
            ? $config[TradingOsConfig::STRATEGY_MARKET_GATES]
            : [];
        $gateDecision = MarketGateEvaluator::evaluate($market, $marketGates);
        $marketMult = (float) $gateDecision['allocation_multiplier'];
        $marketAllowsEntry = (bool) $gateDecision['allows_entry'];
        $defaultPct = round($defaultPct * $marketMult, 4);
        $maxPct = round($maxPct * $marketMult, 4);

        $riskCfg = $config[TradingOsConfig::STRATEGY_RISK] ?? [];
        $allocCfg = $config[TradingOsConfig::STRATEGY_CAPITAL_ALLOCATION] ?? [];

        $capitalSnapshot ??= $this->capitalAccounting->snapshot($profile);
        $strategyId = (int) ($strategy?->id ?? 0);
        $availableCash = $this->strategyAvailableCapital($capitalSnapshot, $strategyId);
        $cashSummary = [
            'cash_balance' => (float) ($capitalSnapshot['physical_cash']['total_cash'] ?? 0),
            'reserved_cash' => (float) ($capitalSnapshot['physical_cash']['pending_execution_reservations'] ?? 0),
            'available_investable_cash' => $availableCash,
        ];

        $results = EvaluationResult::query()
            ->where('evaluation_run_id', $evaluationRun->id)
            ->with(['candidate.security'])
            ->orderBy('rank')
            ->get();
        $blockedMap = $this->dataQualityGuard->blockedStockIdMap(
            $results->pluck('candidate.security_id')->filter()->map(fn ($id) => (int) $id)->all(),
        );
        if ($blockedMap !== []) {
            $results = $results
                ->filter(fn ($result) => empty($blockedMap[(int) ($result->candidate?->security_id ?? 0)]))
                ->values();
        }

        $eligibility = $this->eligibility->resolve($profile, is_array($config) ? $config : []);
        $eligibleSet = array_fill_keys($eligibility['eligible_security_ids'] ?? [], true);
        $eligibilityRestricted = in_array($eligibility['mode'] ?? 'unrestricted', ['screener_union'], true);

        $exitConfig = is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [];
        $exitScreenerHits = $this->eligibility->resolveExitScreenerHits($profile, $exitConfig);

        $heldQty = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('strategy_id', $strategyId)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'stock_id')
            ->all();

        $holdingsByStock = Holding::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->where('strategy_id', $strategyId)
            ->where('quantity', '>', 0)
            ->get()
            ->keyBy(fn (Holding $h) => (int) $h->stock_id);

        $heldQtyInt = [];
        foreach ($heldQty as $stockId => $qty) {
            $heldQtyInt[(int) $stockId] = (float) $qty;
        }
        $heldQty = $heldQtyInt;

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

        $scopedAllocation = [];
        foreach ($heldQty as $stockId => $qty) {
            $meta = $allocationByStock[$stockId] ?? [];
            $price = (float) ($meta['latest_close'] ?? 0);
            if ($price <= 0) {
                $price = (float) ($this->latestClose($stockId) ?? 0);
            }
            $mv = (float) $qty * $price;
            $pct = $portfolioValue > 0 ? ($mv / $portfolioValue) * 100.0 : 0.0;
            $scopedAllocation[$stockId] = [
                'allocation_pct' => $pct,
                'quantity' => (float) $qty,
                'market_value' => $mv,
                'latest_close' => $price,
                'unrealized_pnl_pct' => $meta['unrealized_pnl_pct'] ?? null,
            ];
        }
        $allocationByStock = $scopedAllocation;

        $expiresAt = Carbon::now()->addHours($expiryHours);

        return [
            'profile' => $profile,
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
            'max_holdings' => $maxHoldings,
            'market' => $market,
            'market_gate_decision' => $gateDecision,
            'market_allows_entry' => $marketAllowsEntry,
            'risk_cfg' => $riskCfg,
            'alloc_cfg' => $allocCfg,
            'cash_summary' => $cashSummary,
            'available_cash' => $availableCash,
            'capital_snapshot' => $capitalSnapshot,
            'results' => $results,
            'eligibility' => $eligibility,
            'eligible_set' => $eligibleSet,
            'eligibility_restricted' => $eligibilityRestricted,
            'exit_screener_hits_by_screener' => $exitScreenerHits['by_screener'] ?? [],
            'exit_screener_meta' => $exitScreenerHits['meta'] ?? [],
            'held_qty' => $heldQty,
            'holdings_by_stock' => $holdingsByStock,
            'portfolio_value' => $portfolioValue,
            'allocation_by_stock' => $allocationByStock,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Cancel stale open recommendations for one strategy only (not portfolio-wide).
     */
    protected function cancelStaleRecommendations(PortfolioProfile $profile, ?int $strategyId = null): void
    {
        $query = TradingRecommendation::query()
            ->forProfile($profile)
            ->staleOpen()
            ->with('strategyVersion');

        if ($strategyId !== null && $strategyId > 0) {
            $query->whereHas('strategyVersion', function ($q) use ($strategyId) {
                $q->where('strategy_id', $strategyId);
            });
        }

        $now = Carbon::now();
        foreach ($query->get() as $rec) {
            $recStrategyId = (int) ($rec->strategyVersion?->strategy_id ?? 0);
            $isBuy = in_array($rec->recommendation_type, [
                TradingRecommendation::ACTION_OPEN_POSITION,
                TradingRecommendation::ACTION_INCREASE_POSITION,
            ], true);

            // OD-11: do not stale-replace a BUY while cooldown is active for the pair.
            if ($isBuy && $recStrategyId > 0 && $this->positionTargets->isBuyCooldownActive(
                $profile,
                (int) $rec->security_id,
                $recStrategyId,
                $now,
            )) {
                continue;
            }

            $rec->forceFill(['status' => TradingRecommendation::STATUS_CANCELLED])->save();
        }
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
            $exitAttribution = null;
            if ($isHeld) {
                $holding = $ctx['holdings_by_stock'][$securityId] ?? null;
                if ($holding instanceof Holding) {
                    $stock = $holding->stock ?? \App\Models\Stock::query()->find($securityId);
                    if ($stock !== null) {
                        $precedence = $this->exitPrecedence->evaluate(
                            $ctx['profile'] ?? $holding->profile,
                            $holding,
                            $stock,
                            is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [],
                            [
                                'security_id' => $securityId,
                                'overall_score' => $score,
                                'indicator_scores' => is_array($factorScores) ? $factorScores : [],
                                'indicators' => $result->evidence['indicators'] ?? [],
                                'unrealized_pnl_pct' => $holdingMeta['unrealized_pnl_pct'] ?? null,
                                'exit_screener_hits_by_screener' => $ctx['exit_screener_hits_by_screener'] ?? [],
                            ],
                            is_array($config) ? $config : [],
                        );
                        $exitEval = $precedence['strategy_exit_eval'];
                        $exitAttribution = $this->formatExitAttribution($precedence);
                    }
                } else {
                    // Fallback: strategy-exit only when owner holding row is missing.
                    $exitEval = ExitStrategyEvaluator::evaluate(
                        is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [],
                        [
                            'security_id' => $securityId,
                            'overall_score' => $score,
                            'indicator_scores' => is_array($factorScores) ? $factorScores : [],
                            'indicators' => $result->evidence['indicators'] ?? [],
                            'unrealized_pnl_pct' => $holdingMeta['unrealized_pnl_pct'] ?? null,
                            'exit_screener_hits_by_screener' => $ctx['exit_screener_hits_by_screener'] ?? [],
                        ]
                    );
                    if ($exitEval['triggered'] ?? false) {
                        $exitAttribution = [
                            'primary_reason' => ExitAttribution::STRATEGY_EXIT,
                            'also_true' => [],
                            'mechanisms' => [
                                ExitAttribution::STRATEGY_EXIT => ['triggered' => true, 'detail' => $exitEval],
                            ],
                        ];
                    }
                }
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

            // V3 §3.5 — hard max_holdings: no new OPEN when strategy already at capacity.
            // INCREASE of an existing owned name remains allowed.
            $maxHoldings = (int) ($ctx['max_holdings'] ?? 0);
            $heldNameCount = count($heldQty);
            if (
                $maxHoldings > 0
                && ! $isHeld
                && $action === TradingRecommendation::ACTION_OPEN_POSITION
                && $heldNameCount >= $maxHoldings
            ) {
                $action = TradingRecommendation::ACTION_WATCH;
            }

            // §13.2: any winning exit mechanism forces EXIT (primary attribution on draft).
            $exitTriggered = is_array($exitAttribution) && ($exitAttribution['primary_reason'] ?? null) !== null;
            if ($isHeld && $exitTriggered) {
                $action = TradingRecommendation::ACTION_EXIT_POSITION;
                if (is_array($opinion)) {
                    $dir = (string) ($opinion['direction'] ?? '');
                    if (! in_array($dir, ['STRONG_SELL', 'SELL'], true)) {
                        $opinion['direction'] = 'SELL';
                        $opinion['strength'] = $opinion['strength'] ?? 'moderate';
                    }
                }
            }

            // Non-eligible holdings: only emit if an exit mechanism triggered; otherwise HOLD.
            if ($isHeld && ! $isEligible && ! $exitTriggered) {
                $action = TradingRecommendation::ACTION_HOLD_POSITION;
            }

            // Non-eligible cannot open / increase.
            if (! $isEligible && in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                $action = $isHeld ? TradingRecommendation::ACTION_HOLD_POSITION : TradingRecommendation::ACTION_WATCH;
            }

            // F098: Market gates demote OPEN/INCREASE only; exits and holds remain allowed.
            $marketGateDemoted = false;
            if (! $marketAllowsEntry && in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                $marketGateDemoted = true;
                $action = $isHeld ? TradingRecommendation::ACTION_HOLD_POSITION : TradingRecommendation::ACTION_WATCH;
            }

            $plan = null;
            $suggestedAlloc = $currentAlloc;
            $positionSize = null;
            if (in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                $sized = $this->sizeOpenOrIncrease(
                    $ctx,
                    $action,
                    $securityId,
                    $portfolioValue,
                    $currentAlloc,
                    $targetAlloc,
                    $maxPct,
                    $qtyHeld,
                    is_numeric($referencePrice) ? (float) $referencePrice : null,
                    $risk,
                    $isHeld,
                );
                if ($sized === null) {
                    // OD-11 cooldown or OD-12 below min actionable / zero qty — suppress BUY.
                    $action = $isHeld ? TradingRecommendation::ACTION_HOLD_POSITION : TradingRecommendation::ACTION_WATCH;
                    $suggestedAlloc = $isHeld ? $currentAlloc : 0.0;
                } else {
                    $plan = $sized['plan'];
                    $suggestedAlloc = (float) ($plan['suggested_allocation_pct'] ?? $targetAlloc);
                    $positionSize = (float) ($plan['position_target_amount']
                        ?? $plan['suggested_investment_amount']
                        ?? 0);
                }
            } elseif (in_array($action, TradingRecommendation::ACTIONABLE_ACTIONS, true)) {
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

            if ($action === TradingRecommendation::ACTION_EXIT_POSITION && is_array($exitAttribution)) {
                $plan = $this->attachExitAttributionToPlan(
                    is_array($plan) ? $plan : ['action' => $action],
                    $exitAttribution,
                );
            }

            $priority = (int) max(1, min(100, round($score)));

            $drafts[] = [
                'key' => $index,
                'result' => $result,
                'security_id' => $securityId,
                'symbol' => (string) ($result->candidate?->security?->symbol ?? ''),
                'score' => $score,
                'strategy_breakdown' => $scored['breakdown'],
                'confidence' => $confidence,
                'qty_held' => $qtyHeld,
                'is_held' => $isHeld,
                'is_eligible' => $isEligible,
                'screener_explain' => $screenerExplain,
                'exit_eval' => $exitEval,
                'exit_attribution' => $exitAttribution,
                'current_alloc' => $currentAlloc,
                'target_alloc' => $targetAlloc,
                'suggested_alloc' => $suggestedAlloc,
                'reference_price' => $referencePrice,
                'opinion' => $opinion,
                'action' => $action,
                'market_gate_demoted' => $marketGateDemoted,
                'plan' => $plan,
                'position_size' => $positionSize,
                'priority' => $priority,
                'risk' => $risk,
                'factor_scores' => $factorScores,
            ];
        }

        $drafts = $this->appendScreenerExitOnlyDrafts($drafts, $ctx);
        $drafts = $this->appendPortfolioRiskExitOnlyDrafts($drafts, $ctx);

        return $drafts;
    }

    /**
     * Create EXIT drafts for holdings that hit Screener Exit but were not in the evaluation result set.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function appendScreenerExitOnlyDrafts(array $drafts, array $ctx): array
    {
        $config = $ctx['config'] ?? [];
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
        foreach ($drafts as $draft) {
            $processed[(int) ($draft['security_id'] ?? 0)] = true;
        }

        $heldQty = $ctx['held_qty'] ?? [];
        $allocationByStock = $ctx['allocation_by_stock'] ?? [];
        $eligibility = $ctx['eligibility'] ?? [];
        $eligibleSet = $ctx['eligible_set'] ?? [];
        $eligibilityRestricted = (bool) ($ctx['eligibility_restricted'] ?? false);
        $maxPct = (float) ($ctx['max_pct'] ?? 10);
        $portfolioValue = (float) ($ctx['portfolio_value'] ?? 0);

        foreach ($heldQty as $stockId => $qty) {
            $securityId = (int) $stockId;
            $qtyHeld = (float) $qty;
            if ($securityId < 1 || $qtyHeld <= 0 || isset($processed[$securityId])) {
                continue;
            }

            $exitEval = ExitStrategyEvaluator::evaluate(
                [
                    'enabled' => true,
                    'mode' => 'any',
                    'rules' => $screenerRules,
                ],
                [
                    'security_id' => $securityId,
                    'exit_screener_hits_by_screener' => $ctx['exit_screener_hits_by_screener'] ?? [],
                ]
            );
            if (! ($exitEval['triggered'] ?? false)) {
                continue;
            }

            $holdingMeta = $allocationByStock[$securityId] ?? null;
            $currentAlloc = $holdingMeta ? (float) $holdingMeta['allocation_pct'] : 0.0;
            $referencePrice = $holdingMeta['latest_close'] ?? null;
            if ($referencePrice === null || $referencePrice <= 0) {
                $referencePrice = $this->latestClose($securityId);
            }
            $isEligible = ! $eligibilityRestricted || isset($eligibleSet[$securityId]);
            $opinion = [
                'direction' => 'SELL',
                'strength' => 'moderate',
                'confidence' => 0.7,
                'score' => 0.0,
                'evidence' => ['source' => 'screener_exit'],
            ];
            $action = TradingRecommendation::ACTION_EXIT_POSITION;
            $plan = $this->buildExecutionPlan(
                $action,
                $portfolioValue,
                $currentAlloc,
                0.0,
                $maxPct,
                $qtyHeld,
                is_numeric($referencePrice) ? (float) $referencePrice : null,
                TradingRecommendation::RISK_MEDIUM,
            );

            $holding = $ctx['holdings_by_stock'][$securityId] ?? null;
            $profile = $ctx['profile'] ?? null;
            $exitAttribution = [
                'primary_reason' => ExitAttribution::STRATEGY_EXIT,
                'also_true' => [],
                'mechanisms' => [
                    ExitAttribution::STRATEGY_EXIT => ['triggered' => true, 'detail' => $exitEval],
                ],
            ];
            if ($holding instanceof Holding && $profile instanceof PortfolioProfile) {
                $stock = $holding->stock ?? \App\Models\Stock::query()->find($securityId);
                if ($stock !== null) {
                    $precedence = $this->exitPrecedence->evaluate(
                        $profile,
                        $holding,
                        $stock,
                        is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [],
                        [
                            'security_id' => $securityId,
                            'exit_screener_hits_by_screener' => $ctx['exit_screener_hits_by_screener'] ?? [],
                            'unrealized_pnl_pct' => $holdingMeta['unrealized_pnl_pct'] ?? null,
                        ],
                        is_array($config) ? $config : [],
                    );
                    // Screener-only path already confirmed strategy screener exit; keep strategy_exit primary
                    // if strategy mechanism is true, else use full §13.2 primary.
                    $exitAttribution = $this->formatExitAttribution($precedence);
                    if (($exitAttribution['primary_reason'] ?? null) === null) {
                        $exitAttribution = [
                            'primary_reason' => ExitAttribution::STRATEGY_EXIT,
                            'also_true' => [],
                            'mechanisms' => [
                                ExitAttribution::STRATEGY_EXIT => ['triggered' => true, 'detail' => $exitEval],
                            ],
                        ];
                    }
                    $exitEval = $precedence['strategy_exit_eval'];
                }
            }

            $plan = $this->attachExitAttributionToPlan($plan, $exitAttribution);

            $drafts[] = [
                'key' => 'screener-exit-'.$securityId,
                'result' => null,
                'security_id' => $securityId,
                'symbol' => (string) ($holdingMeta['symbol'] ?? ''),
                'score' => 0.0,
                'strategy_breakdown' => [],
                'confidence' => 0.7,
                'qty_held' => $qtyHeld,
                'is_held' => true,
                'is_eligible' => $isEligible,
                'screener_explain' => $this->eligibility->explainForSecurity($eligibility, $securityId),
                'exit_eval' => $exitEval,
                'exit_attribution' => $exitAttribution,
                'current_alloc' => $currentAlloc,
                'target_alloc' => 0.0,
                'suggested_alloc' => 0.0,
                'reference_price' => $referencePrice,
                'opinion' => $opinion,
                'action' => $action,
                'plan' => $plan,
                'position_size' => $plan['suggested_investment_amount'] ?? null,
                'priority' => 90,
                'risk' => TradingRecommendation::RISK_MEDIUM,
                'factor_scores' => [],
            ];
            $processed[$securityId] = true;
        }

        return $drafts;
    }

    /**
     * EXIT drafts for strategy-owned holdings not already drafted that hit portfolio SL / trailing / horizon
     * (or remaining strategy exit) per §13.2 — covers holdings outside the evaluation result set.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function appendPortfolioRiskExitOnlyDrafts(array $drafts, array $ctx): array
    {
        $profile = $ctx['profile'] ?? null;
        if (! $profile instanceof PortfolioProfile) {
            return $drafts;
        }

        $processed = [];
        foreach ($drafts as $draft) {
            $processed[(int) ($draft['security_id'] ?? 0)] = true;
        }

        $config = is_array($ctx['config'] ?? null) ? $ctx['config'] : [];
        $heldQty = $ctx['held_qty'] ?? [];
        $holdingsByStock = $ctx['holdings_by_stock'] ?? collect();
        $allocationByStock = $ctx['allocation_by_stock'] ?? [];
        $eligibility = $ctx['eligibility'] ?? [];
        $eligibleSet = $ctx['eligible_set'] ?? [];
        $eligibilityRestricted = (bool) ($ctx['eligibility_restricted'] ?? false);
        $maxPct = (float) ($ctx['max_pct'] ?? 10);
        $portfolioValue = (float) ($ctx['portfolio_value'] ?? 0);

        foreach ($heldQty as $stockId => $qty) {
            $securityId = (int) $stockId;
            $qtyHeld = (float) $qty;
            if ($securityId < 1 || $qtyHeld <= 0 || isset($processed[$securityId])) {
                continue;
            }

            $holding = $holdingsByStock[$securityId] ?? null;
            if (! $holding instanceof Holding) {
                continue;
            }
            $stock = $holding->stock ?? \App\Models\Stock::query()->find($securityId);
            if ($stock === null) {
                continue;
            }

            $holdingMeta = $allocationByStock[$securityId] ?? [];
            $precedence = $this->exitPrecedence->evaluate(
                $profile,
                $holding,
                $stock,
                is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [],
                [
                    'security_id' => $securityId,
                    'overall_score' => 0.0,
                    'indicator_scores' => [],
                    'indicators' => [],
                    'unrealized_pnl_pct' => $holdingMeta['unrealized_pnl_pct'] ?? null,
                    'exit_screener_hits_by_screener' => $ctx['exit_screener_hits_by_screener'] ?? [],
                ],
                $config,
            );

            $exitAttribution = $this->formatExitAttribution($precedence);
            if (($exitAttribution['primary_reason'] ?? null) === null) {
                continue;
            }

            $currentAlloc = (float) ($holdingMeta['allocation_pct'] ?? 0);
            $referencePrice = $holdingMeta['latest_close'] ?? null;
            if ($referencePrice === null || $referencePrice <= 0) {
                $referencePrice = $this->latestClose($securityId);
            }
            $isEligible = ! $eligibilityRestricted || isset($eligibleSet[$securityId]);
            $action = TradingRecommendation::ACTION_EXIT_POSITION;
            $plan = $this->buildExecutionPlan(
                $action,
                $portfolioValue,
                $currentAlloc,
                0.0,
                $maxPct,
                $qtyHeld,
                is_numeric($referencePrice) ? (float) $referencePrice : null,
                TradingRecommendation::RISK_MEDIUM,
            );
            $plan = $this->attachExitAttributionToPlan($plan, $exitAttribution);

            $drafts[] = [
                'key' => 'risk-exit-'.$securityId,
                'result' => null,
                'security_id' => $securityId,
                'symbol' => (string) ($stock->symbol ?? ''),
                'score' => 0.0,
                'strategy_breakdown' => [],
                'confidence' => 0.7,
                'qty_held' => $qtyHeld,
                'is_held' => true,
                'is_eligible' => $isEligible,
                'screener_explain' => $this->eligibility->explainForSecurity($eligibility, $securityId),
                'exit_eval' => $precedence['strategy_exit_eval'],
                'exit_attribution' => $exitAttribution,
                'current_alloc' => $currentAlloc,
                'target_alloc' => 0.0,
                'suggested_alloc' => 0.0,
                'reference_price' => $referencePrice,
                'opinion' => [
                    'direction' => 'SELL',
                    'strength' => 'moderate',
                    'confidence' => 0.7,
                    'score' => 0.0,
                    'evidence' => ['source' => 'exit_precedence', 'primary_reason' => $exitAttribution['primary_reason']],
                ],
                'action' => $action,
                'plan' => $plan,
                'position_size' => $plan['suggested_investment_amount'] ?? null,
                'priority' => 85,
                'risk' => TradingRecommendation::RISK_MEDIUM,
                'factor_scores' => [],
            ];
            $processed[$securityId] = true;
        }

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $precedence
     * @return array{primary_reason: ?string, also_true: list<string>, mechanisms: array<string, mixed>}
     */
    protected function formatExitAttribution(array $precedence): array
    {
        return [
            'primary_reason' => $precedence['primary_reason'] ?? null,
            'also_true' => array_values($precedence['also_true'] ?? []),
            'mechanisms' => $precedence['mechanisms'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $plan
     * @param  array<string, mixed>  $exitAttribution
     * @return array<string, mixed>|null
     */
    protected function attachExitAttributionToPlan(?array $plan, array $exitAttribution): ?array
    {
        if ($plan === null) {
            return null;
        }
        $plan['exit_attribution'] = $exitAttribution;
        $plan['primary_exit_reason'] = $exitAttribution['primary_reason'] ?? null;

        return $plan;
    }

    /**
     * Order OPEN/INCREASE drafts by V3 return-quality ranking when computable,
     * otherwise OD-23 capital fill order. Non-buy drafts follow after.
     * Score is not the primary ranking key.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function rankDrafts(array $drafts, array $ctx): array
    {
        $buy = [];
        $other = [];
        foreach ($drafts as $draft) {
            if (in_array($draft['action'] ?? '', [
                TradingRecommendation::ACTION_OPEN_POSITION,
                TradingRecommendation::ACTION_INCREASE_POSITION,
            ], true)) {
                $buy[] = $draft;
            } else {
                $other[] = $draft;
            }
        }

        return array_merge($this->orderBuyDrafts($buy, $ctx), $other);
    }

    /**
     * @param  list<array<string, mixed>>  $buy
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function orderBuyDrafts(array $buy, array $ctx): array
    {
        if ($buy === []) {
            return [];
        }

        $versionId = (int) ($ctx['strategy_version']->id ?? 0);
        $ranking = $versionId > 0
            ? $this->returnQualityRanking->rankForStrategyVersion($versionId)
            : ['computable' => false, 'bands' => [], 'reason' => 'No strategy version'];

        $rankable = [];
        $fallback = [];
        foreach ($buy as $draft) {
            $fit = (float) ($draft['score'] ?? 0);
            $quality = $this->returnQualityForFit($ranking, $fit);
            $draft['return_quality'] = $quality;
            $draft['ranking_computable'] = $quality !== null;
            $draft['ranking_order_source'] = $quality !== null ? 'return_quality' : 'od23';
            if ($quality !== null) {
                $rankable[] = $draft;
            } else {
                $fallback[] = $draft;
            }
        }

        usort($rankable, function (array $a, array $b): int {
            $q = ((float) ($b['return_quality'] ?? 0)) <=> ((float) ($a['return_quality'] ?? 0));
            if ($q !== 0) {
                return $q;
            }
            $fit = ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0));
            if ($fit !== 0) {
                return $fit;
            }

            return strtoupper((string) ($a['symbol'] ?? '')) <=> strtoupper((string) ($b['symbol'] ?? ''));
        });

        $fillInput = [];
        foreach ($fallback as $i => $draft) {
            $fillInput[] = [
                'target_amount' => (float) ($draft['plan']['suggested_investment_amount'] ?? $draft['position_size'] ?? 0),
                'fit_score' => (float) ($draft['score'] ?? 0),
                'symbol' => (string) ($draft['symbol'] ?? ''),
                'draft_index' => $i,
                'draft' => $draft,
            ];
        }
        $orderedFallback = $this->capitalFillOrder->order($fillInput);
        $fallbackDrafts = [];
        foreach ($orderedFallback as $row) {
            $fallbackDrafts[] = $row['draft'];
        }

        return array_merge($rankable, $fallbackDrafts);
    }

    /**
     * @param  array<string, mixed>  $ranking
     */
    protected function returnQualityForFit(array $ranking, float $fitScore): ?float
    {
        if (! ($ranking['computable'] ?? false)) {
            return null;
        }

        foreach ($ranking['bands'] ?? [] as $band) {
            if (! ($band['eligible'] ?? false)) {
                continue;
            }
            foreach ($band['merged_band_keys'] ?? [] as $key) {
                if ($this->returnQualityRanking->bandKeyForScore($fitScore) === $key) {
                    return $band['return_quality'] !== null ? (float) $band['return_quality'] : null;
                }
            }
        }

        return null;
    }

    /**
     * V3 §3.5 — after ranking, demote excess new OPEN drafts to WATCH so generation
     * never emits more new names than remaining max_holdings slots.
     * INCREASE of already-owned names is unchanged.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    protected function enforceMaxHoldingsOpenCap(array $drafts, array $ctx): array
    {
        $maxHoldings = (int) ($ctx['max_holdings'] ?? 0);
        if ($maxHoldings < 1) {
            return $drafts;
        }

        $heldCount = count($ctx['held_qty'] ?? []);
        $slots = max(0, $maxHoldings - $heldCount);
        $openSeen = 0;

        foreach ($drafts as &$draft) {
            if (($draft['action'] ?? null) !== TradingRecommendation::ACTION_OPEN_POSITION) {
                continue;
            }
            if ($openSeen >= $slots) {
                $draft['action'] = TradingRecommendation::ACTION_WATCH;
                $draft['plan'] = null;
                $draft['position_size'] = null;
                $draft['suggested_alloc'] = 0.0;
            } else {
                $openSeen++;
            }
        }
        unset($draft);

        return $drafts;
    }

    /**
     * Allocate strategy_available_capital across already-ordered buy drafts.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return array<int|string, array<string, mixed>>
     */
    protected function allocateCapital(array $drafts, array $ctx): array
    {
        $portfolioValue = $ctx['portfolio_value'];
        $maxPct = $ctx['max_pct'];
        $maxNewPositions = $ctx['max_new_positions'];
        $maxHoldings = (int) ($ctx['max_holdings'] ?? 0);
        $heldCount = count($ctx['held_qty'] ?? []);
        $availableCash = (float) ($ctx['available_cash'] ?? 0);

        $buyDrafts = [];
        $maxPositionAmount = $portfolioValue > 0 ? round($portfolioValue * ($maxPct / 100.0), 4) : null;
        $newOpenCount = 0;
        foreach ($drafts as $draft) {
            if (! in_array($draft['action'], [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                continue;
            }
            if ($draft['action'] === TradingRecommendation::ACTION_OPEN_POSITION) {
                if ($maxHoldings > 0 && ($heldCount + $newOpenCount) >= $maxHoldings) {
                    continue;
                }
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
                'symbol' => (string) ($draft['symbol'] ?? ''),
                'order_source' => (string) ($draft['ranking_order_source'] ?? 'od23'),
            ];
        }

        return $this->allocator->allocate($availableCash, $buyDrafts);
    }

    /**
     * Apply capital allocation outcomes. OPEN/INCREASE stay OPEN/INCREASE when
     * unfunded or partially funded (OD-05). Target amount is preserved.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<int|string, array<string, mixed>>  $allocations
     * @return list<array<string, mixed>>
     */
    protected function applyCapitalOutcomes(array $drafts, array $allocations): array
    {
        foreach ($drafts as &$draft) {
            $action = $draft['action'];
            $plan = $draft['plan'];
            $suggestedAlloc = $draft['suggested_alloc'];
            $positionSize = $draft['position_size'];
            $capitalAllocationMeta = null;

            if (in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                $alloc = $allocations[$draft['key']] ?? ['allocated_amount' => 0.0, 'quantity' => 0];
                $qty = (int) ($alloc['quantity'] ?? 0);
                $amount = round((float) ($alloc['allocated_amount'] ?? 0), 4);
                $desiredAmount = (float) ($alloc['target_amount']
                    ?? $draft['plan']['this_cycle_amount']
                    ?? $draft['plan']['target_investment_amount']
                    ?? $draft['plan']['suggested_investment_amount']
                    ?? $draft['position_size']
                    ?? 0);
                $positionTarget = (float) ($draft['plan']['position_target_amount']
                    ?? $draft['position_target_amount']
                    ?? $desiredAmount);
                $unfunded = (float) ($alloc['unfunded_amount'] ?? max(0.0, $desiredAmount - $amount));
                $status = $this->mapFundingStatus($alloc, $qty, $amount);

                $capitalAllocationMeta = [
                    'status' => $status,
                    // This-cycle requirement (staggered / remaining) — lending remainder uses this (DEP-PARTIAL-*).
                    'desired_amount' => $desiredAmount,
                    'target_amount' => $desiredAmount,
                    // OD-12 position target (full conviction); not reduced by partial funding.
                    'position_target_amount' => $positionTarget,
                    'allocated_amount' => $amount,
                    'unfunded_amount' => $unfunded,
                    'quantity' => $qty,
                    'atomic_reservation' => (float) ($alloc['atomic_reservation'] ?? 0),
                    'order_source' => (string) ($draft['ranking_order_source'] ?? 'od23'),
                    'is_first_entry' => (bool) ($draft['plan']['is_first_entry'] ?? false),
                    'filled_amount' => (float) ($draft['plan']['filled_amount'] ?? 0),
                    'remaining_amount' => (float) ($draft['plan']['remaining_amount'] ?? 0),
                ];

                $plan = is_array($plan) ? $plan : [];
                $plan['position_target_amount'] = $positionTarget;
                $plan['this_cycle_amount'] = $desiredAmount;
                $plan['target_investment_amount'] = $desiredAmount;
                $plan['suggested_quantity'] = $qty;
                $plan['suggested_investment_amount'] = $amount;
                $plan['capital_allocation'] = $capitalAllocationMeta;
                if (isset($plan['position_after']) && is_array($plan['position_after'])) {
                    $plan['position_after']['quantity_delta'] = $qty;
                }
                $positionSize = $positionTarget;
                $suggestedAlloc = $draft['target_alloc'];
            }

            $draft['action'] = $action;
            $draft['plan'] = $plan;
            $draft['suggested_alloc'] = $suggestedAlloc;
            $draft['position_size'] = $positionSize;
            if ($capitalAllocationMeta !== null) {
                $draft['capital_allocation'] = $capitalAllocationMeta;
            }
        }
        unset($draft);

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $alloc
     */
    protected function mapFundingStatus(array $alloc, int $qty, float $amount): string
    {
        $raw = (string) ($alloc['funding_status'] ?? '');
        if ($raw === 'PARTIALLY_FUNDED') {
            return TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED;
        }
        if ($raw === 'UNFUNDED' || $qty < 1 || $amount <= 0) {
            return TradingRecommendation::ALLOCATION_UNFUNDED;
        }

        return TradingRecommendation::ALLOCATION_FUNDED;
    }

    /**
     * Persist TradingRecommendation rows from drafts that already have capital outcomes applied.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<string, mixed>  $ctx
     * @return list<TradingRecommendation>
     */
    protected function persistDrafts(array $drafts, array $ctx, PortfolioProfile $profile): array
    {
        $maxConcurrent = $ctx['max_concurrent'];
        $strategyVersion = $ctx['strategy_version'];
        $strategy = $ctx['strategy'] ?? null;
        $eligibility = $ctx['eligibility'];
        $market = $ctx['market'];
        $gateDecision = $ctx['market_gate_decision'] ?? MarketGateEvaluator::evaluate($market, []);
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
            $capitalAllocationMeta = $draft['capital_allocation'] ?? null;

            if (in_array($action, [TradingRecommendation::ACTION_OPEN_POSITION, TradingRecommendation::ACTION_INCREASE_POSITION], true)) {
                if (is_array($plan) && isset($plan['suggested_investment_amount'])) {
                    $suggestedAllocationAmount = (float) $plan['suggested_investment_amount'];
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
                (bool) ($draft['market_gate_demoted'] ?? false),
                is_array($gateDecision['strategy_gates']['block_reasons'] ?? null)
                    ? $gateDecision['strategy_gates']['block_reasons']
                    : [],
            );

            $evidence = [
                'score' => $draft['score'],
                'strategy_score' => $draft['score'],
                'strategy_version_id' => $strategyVersion->id,
                'strategy_version' => $strategyVersion->version,
                'strategy_name' => $strategy->name ?? 'Strategy',
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
                'exit_attribution' => $draft['exit_attribution'] ?? null,
                'rank' => $draft['result']?->rank,
                'passed_rules' => $draft['result']?->passed_rules ?? [],
                'failed_rules' => $draft['result']?->failed_rules ?? [],
                'indicators' => $draft['result']?->evidence['indicators'] ?? [],
                'factor_scores' => $draft['factor_scores'],
                'discovery' => $draft['result']?->evidence['discovery'] ?? [],
                'held' => $draft['is_held'],
                'market_opinion' => $draft['opinion'],
                'portfolio_action' => $action,
                'portfolio_decision' => $action,
                'market_analysis' => [
                    'market_phase' => $market['market_phase'] ?? null,
                    'sentiment_score' => $market['sentiment']['score'] ?? null,
                    'sentiment_label' => $market['sentiment']['label'] ?? null,
                    'risk_label' => $market['risk']['label'] ?? null,
                    'risk_raw' => $market['risk']['raw_risk'] ?? null,
                    'base_new_entry_allowed' => $gateDecision['market_analysis']['new_entry_allowed'] ?? true,
                    'base_allocation_multiplier' => $gateDecision['market_analysis']['allocation_multiplier'] ?? 1,
                    'effective_new_entry_allowed' => $marketAllowsEntry,
                    'effective_allocation_multiplier' => $gateDecision['allocation_multiplier'] ?? 1,
                    'new_entry_allowed' => $marketAllowsEntry,
                    'allocation_multiplier' => $gateDecision['allocation_multiplier'] ?? 1,
                ],
                'market_gates' => $gateDecision['strategy_gates'] ?? [],
                'market_gate_demoted' => (bool) ($draft['market_gate_demoted'] ?? false),
            ];
            if ($capitalAllocationMeta !== null) {
                $evidence['capital_allocation'] = $capitalAllocationMeta;
            }
            if (isset($draft['ranking_order_source'])) {
                $evidence['ranking'] = [
                    'order_source' => $draft['ranking_order_source'],
                    'computable' => (bool) ($draft['ranking_computable'] ?? false),
                    'return_quality' => $draft['return_quality'] ?? null,
                ];
            }

            $rec = TradingRecommendation::query()->create([
                'profile_id' => $profile->id,
                'evaluation_result_id' => $draft['result']?->id,
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
                'failed_checks' => $draft['result']?->failed_rules ?? [],
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

            // OD-12: persist position target on strategy-owned holding (survives perishable rec rows).
            if (
                $strategy instanceof TradingStrategy
                && in_array($action, [
                    TradingRecommendation::ACTION_OPEN_POSITION,
                    TradingRecommendation::ACTION_INCREASE_POSITION,
                ], true)
            ) {
                $positionTarget = (float) ($plan['position_target_amount']
                    ?? $capitalAllocationMeta['position_target_amount']
                    ?? 0);
                if ($positionTarget > 0) {
                    $stock = \App\Models\Stock::query()->find((int) $draft['security_id']);
                    if ($stock !== null) {
                        $this->positionTargets->upsertTargetAmount(
                            $profile,
                            $stock,
                            $strategy,
                            $positionTarget,
                        );
                    }
                }
            }

            $this->lending->syncAfterGenerated($rec);
            $created[] = $rec->fresh();
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

        // Held
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

        if ($direction === TradingRecommendation::OPINION_BULLISH || $score >= $buyMin) {
            return TradingRecommendation::ACTION_HOLD_POSITION;
        }

        return TradingRecommendation::ACTION_HOLD_POSITION;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * OD-11 / OD-12: size OPEN/INCREASE using position target, staggered first entry / remaining,
     * whole-share floor, and minimum actionable amount. Returns null when BUY must be suppressed.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{plan: array<string, mixed>}|null
     */
    protected function sizeOpenOrIncrease(
        array $ctx,
        string $action,
        int $securityId,
        float $portfolioValue,
        float $currentAlloc,
        float $targetAlloc,
        float $maxPct,
        float $qtyHeld,
        ?float $price,
        string $risk,
        bool $isHeld,
    ): ?array {
        $profile = $ctx['profile'] ?? null;
        $strategy = $ctx['strategy'] ?? null;
        if (! $profile instanceof PortfolioProfile || ! $strategy instanceof TradingStrategy) {
            return null;
        }

        $strategyId = (int) $strategy->id;
        if ($this->positionTargets->isBuyCooldownActive($profile, $securityId, $strategyId)) {
            return null;
        }

        $capAlloc = min($maxPct, $targetAlloc);
        $convictionTarget = $portfolioValue > 0
            ? round($portfolioValue * ($capAlloc / 100.0), 4)
            : 0.0;
        if ($convictionTarget <= 0) {
            return null;
        }

        $holding = $this->positionTargets->findHolding($profile, $securityId, $strategyId);
        $hasOpen = $isHeld || $this->positionTargets->hasOpenPosition($holding);
        $filled = $this->positionTargets->filledAmount($holding);

        // Recap target from current conviction; do not overwrite with this-cycle or allocated.
        $positionTarget = $convictionTarget;
        $firstEntryPct = is_numeric($ctx['config']['portfolio_rules']['first_entry_pct'] ?? null)
            ? (float) $ctx['config']['portfolio_rules']['first_entry_pct']
            : null;

        $cycle = $this->staggeredEntry->thisCycleIntendedAmount(
            $positionTarget,
            $filled,
            $hasOpen,
            $firstEntryPct,
        );
        $thisCycle = (float) $cycle['this_cycle_amount'];
        if ($thisCycle <= 0) {
            return null;
        }

        $refPrice = $price !== null && $price > 0 ? $price : 0.0;
        $shares = $this->wholeShares->fromAmount($thisCycle, $refPrice);
        $qty = (int) $shares['quantity'];
        $notional = (float) $shares['notional'];
        if ($qty < 1 || $notional <= 0) {
            return null;
        }

        if (! $this->minActionable->isActionable($notional, $profile)) {
            return null;
        }

        $suggestedAlloc = $action === TradingRecommendation::ACTION_OPEN_POSITION
            ? $capAlloc
            : min($maxPct, max($currentAlloc, $capAlloc));

        $plan = [
            'action' => $action,
            'suggested_target_allocation_pct' => round($capAlloc, 4),
            'suggested_allocation_pct' => round($suggestedAlloc, 4),
            'risk_explanation' => $this->riskExplanation($action, $risk, $currentAlloc, $capAlloc),
            'side' => 'buy',
            'position_target_amount' => round($positionTarget, 4),
            'this_cycle_amount' => round($notional, 4),
            'suggested_investment_amount' => round($notional, 4),
            'target_investment_amount' => round($notional, 4),
            'suggested_quantity' => $qty,
            'filled_amount' => round($filled, 4),
            'remaining_amount' => round((float) $cycle['remaining_amount'], 4),
            'is_first_entry' => (bool) $cycle['is_first_entry'],
            'first_entry_pct' => (float) $cycle['first_entry_pct'],
            'position_after' => [
                'allocation_pct' => round($suggestedAlloc, 4),
                'quantity_delta' => $qty,
            ],
        ];

        return ['plan' => $plan];
    }

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

        if ($action === TradingRecommendation::ACTION_OPEN_POSITION || $action === TradingRecommendation::ACTION_INCREASE_POSITION) {
            $suggestedAlloc = $action === TradingRecommendation::ACTION_OPEN_POSITION ? $capAlloc : max($currentAlloc, $capAlloc);
            if ($action === TradingRecommendation::ACTION_INCREASE_POSITION) {
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
        } elseif ($action === TradingRecommendation::ACTION_REDUCE_POSITION) {
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
        } elseif ($action === TradingRecommendation::ACTION_EXIT_POSITION) {
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
            TradingRecommendation::ACTION_OPEN_POSITION => "Open toward ~{$target}% of portfolio (risk: {$risk}). Respects max position size.",
            TradingRecommendation::ACTION_INCREASE_POSITION => "Increase from {$current}% toward ~{$target}% (risk: {$risk}).",
            TradingRecommendation::ACTION_REDUCE_POSITION => "Reduce from {$current}% toward ~{$target}% (risk: {$risk}).",
            TradingRecommendation::ACTION_EXIT_POSITION => "Exit full position (risk: {$risk}).",
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
        bool $marketGateDemoted = false,
        array $gateBlockReasons = [],
    ): string {
        $parts = [
            "Market: {$opinion['direction']} ({$opinion['strength']}).",
            $isHeld ? "Held at {$currentAlloc}% vs target {$targetAlloc}%." : 'No current holding.',
            "Portfolio action: {$action}.",
            "Risk: {$risk}.",
        ];
        if ($marketGateDemoted) {
            $reasonText = $gateBlockReasons !== []
                ? implode('; ', $gateBlockReasons)
                : 'new entries blocked by market gates';
            $parts[] = "Market gates demoted entry: {$reasonText}.";
        }

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

    /**
     * @return list<TradingStrategyVersion>
     */
    protected function enabledStrategyVersions(PortfolioProfile $profile): array
    {
        $strategies = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->with('activeVersion')
            ->orderBy('id')
            ->get();

        $versions = [];
        foreach ($strategies as $strategy) {
            if ($strategy->activeVersion) {
                $versions[] = $strategy->activeVersion;
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function strategyAvailableCapital(array $snapshot, int $strategyId): float
    {
        foreach ($snapshot['strategies'] ?? [] as $row) {
            if ((int) ($row['strategy_id'] ?? 0) === $strategyId) {
                return (float) ($row['strategy_available_capital'] ?? 0);
            }
        }

        return 0.0;
    }
}
