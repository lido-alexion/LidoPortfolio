<?php

namespace App\Engines\Recommendation;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\StockPrice;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Recommendation Engine — three stages:
 * 1) Market Opinion (portfolio-independent)
 * 2) Portfolio Decision (position / allocation aware)
 * 3) Execution Plan (actionable actions only)
 */
class RecommendationEngine
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{recommendations: list<TradingRecommendation>, batch_id: string}
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

        $config = config('trading_os.recommendation', []);
        $buyMin = (float) ($config['buy_score_min'] ?? 65);
        $watchMin = (float) ($config['watch_score_min'] ?? 45);
        $sellMax = (float) ($config['sell_score_max'] ?? 35);
        $expiryHours = (int) ($config['expiry_hours'] ?? 48);
        $defaultPct = (float) ($config['default_position_pct'] ?? 5);
        $maxPct = (float) ($config['max_position_pct'] ?? 10);
        $allocationBand = (float) ($config['allocation_band_pct'] ?? 1.0);
        $riskCfg = $config['risk'] ?? [];

        $results = EvaluationResult::query()
            ->where('evaluation_run_id', $evaluationRun->id)
            ->with(['candidate.security'])
            ->orderBy('rank')
            ->get();

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
            $watchMin,
            $sellMax,
            $expiresAt,
            $defaultPct,
            $maxPct,
            $allocationBand,
            $portfolioValue,
            $riskCfg,
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

            foreach ($results as $result) {
                $securityId = (int) $result->candidate->security_id;
                $score = (float) $result->score;
                $confidence = (float) $result->confidence;
                $qtyHeld = (float) ($heldQty[$securityId] ?? 0);
                $isHeld = $qtyHeld > 0;
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

                // Stage 1 — Market Opinion (no portfolio context).
                $opinion = $this->buildMarketOpinion($score, $confidence, $buyMin, $watchMin, $sellMax, $result);

                // Stage 2 — Portfolio Decision.
                $targetAlloc = min($maxPct, $defaultPct);
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
                    $sellMax,
                );

                // Stage 3 — Execution Plan (actionable only).
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

                $reasoning = $this->buildReasoning($opinion, $action, $currentAlloc, $targetAlloc, $isHeld, $risk);
                $priority = (int) max(1, min(100, round($score)));

                $created[] = TradingRecommendation::query()->create([
                    'profile_id' => $profile->id,
                    'evaluation_result_id' => $result->id,
                    'security_id' => $securityId,
                    'recommendation_type' => $action,
                    'market_opinion' => $opinion,
                    'execution_plan' => $plan,
                    'priority' => $priority,
                    'confidence' => $confidence,
                    'risk_level' => $risk,
                    'suggested_position_size' => $positionSize,
                    'reference_price' => $referencePrice,
                    'current_allocation_pct' => round($currentAlloc, 4),
                    'target_allocation_pct' => round($targetAlloc, 4),
                    'suggested_allocation_pct' => round($suggestedAlloc, 4),
                    'status' => TradingRecommendation::initialStatusForAction($action),
                    'evidence' => [
                        'score' => $score,
                        'rank' => $result->rank,
                        'passed_rules' => $result->passed_rules,
                        'failed_rules' => $result->failed_rules,
                        'indicators' => $result->evidence['indicators'] ?? [],
                        'discovery' => $result->evidence['discovery'] ?? [],
                        'held' => $isHeld,
                        'market_opinion' => $opinion,
                        'portfolio_action' => $action,
                    ],
                    'failed_checks' => $result->failed_rules ?? [],
                    'reasoning' => $reasoning,
                    'version' => 2,
                    'expires_at' => $expiresAt,
                    'generated_at' => now(),
                ]);
            }
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendations generated', [
            'profile_id' => $profile->id,
            'count' => count($created),
            'evaluation_run_id' => $evaluationRun->id,
        ]);

        return [
            'recommendations' => $created,
            'batch_id' => 'eval-'.$evaluationRun->id.'-'.now()->format('YmdHis'),
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
            $score >= 85 || $score <= 15 => 'Very Strong',
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
        float $sellMax,
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

        if ($direction === 'Bearish' || ($highRisk && $overTarget)) {
            return 'REDUCE_POSITION';
        }

        if ($overTarget && ($direction !== 'Bullish' || $highRisk)) {
            return 'REDUCE_POSITION';
        }

        if ($direction === 'Bullish' && $underTarget) {
            return 'INCREASE_POSITION';
        }

        if ($strongBull && $underTarget) {
            return 'INCREASE_POSITION';
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

        $decision = strtolower(trim($decision));
        if (! in_array($decision, TradingRecommendation::REVIEW_DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be accepted, rejected, or deferred.'],
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

        DB::transaction(function () use ($recommendation, $user, $decision, $notes) {
            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $recommendation->forceFill(['status' => $decision])->save();
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation reviewed', [
            'recommendation_id' => $recommendation->id,
            'decision' => $decision,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Undo Accept / Reject / Defer → pending_review. Cancels any pending orders for this recommendation.
     * Executed recommendations must have their fill deleted first (reopens via ExecutionEngine).
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
            ])->save();
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
                TradingRecommendation::STATUS_ACCEPTED,
            ],
            100,
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
            ->with(['security', 'evaluationResult.candidate', 'reviews.user', 'orders'])
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
