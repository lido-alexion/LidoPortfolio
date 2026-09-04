<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingRecommendation extends Model
{
    protected $table = 'portfolio_tos_recommendations';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    /** Informational — visible, no user approval. */
    public const STATUS_PUBLISHED = 'published';

    /**
     * @deprecated Use STATUS_PENDING_EXECUTION. Kept for API/BC during migration.
     */
    public const STATUS_ACCEPTED = 'accepted';

    /** Approved for execution — waiting for a ledger transaction (or future broker fill). */
    public const STATUS_PENDING_EXECUTION = 'pending_execution';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPERSEDED = 'superseded';

    /** Approved but will not be executed (operator cancelled the execution). */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ARCHIVED = 'archived';

    /** Legacy rows pre–status migration; treated as open when cancelling/expiring stale. */
    public const STATUS_ACTIVE_LEGACY = 'active';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const ALLOCATION_FUNDED = 'funded';

    public const ALLOCATION_PARTIALLY_FUNDED = 'partially_funded';

    public const ALLOCATION_UNFUNDED = 'unfunded';

    public const ALLOCATION_AWAITING_LENDER_SELECTION = 'awaiting_lender_selection';

    public const ALLOCATION_CAPITAL_COMMITTED = 'capital_committed';

    public const OPINION_BULLISH = 'Bullish';

    public const OPINION_BEARISH = 'Bearish';

    public const OPINION_NEUTRAL = 'Neutral';

    public const STRENGTH_VERY_STRONG = 'Very Strong';

    public const STRENGTH_STRONG = 'Strong';

    public const STRENGTH_MODERATE = 'Moderate';

    public const STRENGTH_WEAK = 'Weak';

    public const ACTION_OPEN_POSITION = 'OPEN_POSITION';

    public const ACTION_INCREASE_POSITION = 'INCREASE_POSITION';

    public const ACTION_REDUCE_POSITION = 'REDUCE_POSITION';

    public const ACTION_EXIT_POSITION = 'EXIT_POSITION';

    public const ACTION_HOLD_POSITION = 'HOLD_POSITION';

    public const ACTION_WATCH = 'WATCH';

    /** Review decisions accepted by the API (approved maps to pending_execution). */
    public const REVIEW_DECISIONS = [
        'approved',
        'accepted', // BC alias → approved / pending_execution
        self::STATUS_REJECTED,
        self::STATUS_DEFERRED,
    ];

    /** Audit-only decision when a review is undone. */
    public const DECISION_REOPENED = 'reopened';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_EXECUTION_CANCELLED = 'execution_cancelled';

    public const DECISION_EXPIRED = 'expired';

    public const RESERVATION_NONE = 'none';

    public const RESERVATION_RESERVED = 'reserved';

    public const RESERVATION_RELEASED = 'released';

    public const RESERVATION_CONVERTED = 'converted';

    public const CANCELLATION_REASONS = [
        'price_moved',
        'market_conditions',
        'funds_unavailable',
        'broker_rejected',
        'executed_outside_system',
        'no_longer_valid',
        'other',
        'mode_changed_to_manual',
    ];

    public const CANCELLATION_REASON_LABELS = [
        'price_moved' => 'Price moved significantly',
        'market_conditions' => 'Market conditions changed',
        'funds_unavailable' => 'Funds unavailable',
        'broker_rejected' => 'Broker rejected order',
        'executed_outside_system' => 'Executed outside system',
        'no_longer_valid' => 'Recommendation no longer valid',
        'other' => 'Other',
        'mode_changed_to_manual' => 'Execution mode changed to Manual',
    ];

    /** Portfolio actions that require review then pending execution. */
    public const ACTIONABLE_ACTIONS = [
        self::ACTION_OPEN_POSITION,
        self::ACTION_INCREASE_POSITION,
        self::ACTION_REDUCE_POSITION,
        self::ACTION_EXIT_POSITION,
    ];

    /** Portfolio actions that are auto-published. */
    public const INFORMATIONAL_ACTIONS = [
        self::ACTION_HOLD_POSITION,
        self::ACTION_WATCH,
    ];

    /** Default API "open" recommendation list (pending review + execution + published). */
    public const OPEN_LIST_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_DEFERRED,
        self::STATUS_PENDING_EXECUTION,
        self::STATUS_ACCEPTED,
        self::STATUS_PUBLISHED,
    ];

    /** Rows eligible for expiry or cancellation when a new generation batch runs. */
    public const STALE_OPEN_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_DEFERRED,
        self::STATUS_PUBLISHED,
        self::STATUS_ACTIVE_LEGACY,
    ];

    /** @deprecated Use ACTIONABLE_ACTIONS */
    public const ACTIONABLE_TYPES = self::ACTIONABLE_ACTIONS;

    /** @deprecated Use INFORMATIONAL_ACTIONS */
    public const INFORMATIONAL_TYPES = self::INFORMATIONAL_ACTIONS;

    public const UI_LABELS = [
        self::ACTION_OPEN_POSITION => 'Buy',
        self::ACTION_INCREASE_POSITION => 'Buy More',
        self::ACTION_REDUCE_POSITION => 'Sell Partial',
        self::ACTION_EXIT_POSITION => 'Sell All',
        self::ACTION_HOLD_POSITION => 'Hold',
        self::ACTION_WATCH => 'Watch',
        'BUY' => 'Buy',
        'SELL' => 'Sell All',
        'HOLD' => 'Hold',
    ];

    protected $fillable = [
        'profile_id',
        'evaluation_result_id',
        'strategy_version_id',
        'security_id',
        'recommendation_type',
        'market_opinion',
        'execution_plan',
        'priority',
        'strategy_score',
        'confidence',
        'risk_level',
        'suggested_position_size',
        'suggested_allocation_amount',
        'reserved_amount',
        'reservation_status',
        'reserved_at',
        'cash_balance_at_generation',
        'reserved_cash_at_generation',
        'available_cash_at_generation',
        'executed_amount',
        'reference_price',
        'current_allocation_pct',
        'target_allocation_pct',
        'suggested_allocation_pct',
        'status',
        'evidence',
        'failed_checks',
        'reasoning',
        'version',
        'expires_at',
        'generated_at',
        'approved_at',
        'cancelled_at',
        'cancellation_reason',
        'executed_at',
        'executed_transaction_id',
        'target_amount',
        'capital_resolved_amount',
        'internal_executed_amount',
        'external_executed_amount',
        'remaining_target_amount',
        'original_display_quantity',
        'execution_anchor_date',
        'execution_anchor_class',
        'first_eligible_execution_date',
        'second_eligible_execution_date',
        'execution_expires_at',
        'superseded_at',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'strategy_version_id' => 'integer',
            'strategy_score' => 'decimal:4',
            'confidence' => 'decimal:4',
            'suggested_position_size' => 'decimal:4',
            'suggested_allocation_amount' => 'decimal:4',
            'reserved_amount' => 'decimal:4',
            'cash_balance_at_generation' => 'decimal:4',
            'reserved_cash_at_generation' => 'decimal:4',
            'available_cash_at_generation' => 'decimal:4',
            'executed_amount' => 'decimal:4',
            'reference_price' => 'decimal:4',
            'current_allocation_pct' => 'decimal:4',
            'target_allocation_pct' => 'decimal:4',
            'suggested_allocation_pct' => 'decimal:4',
            'market_opinion' => 'array',
            'execution_plan' => 'array',
            'evidence' => 'array',
            'failed_checks' => 'array',
            'expires_at' => 'datetime',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'executed_at' => 'datetime',
            'reserved_at' => 'datetime',
            'executed_transaction_id' => 'integer',
            'target_amount' => 'decimal:4',
            'capital_resolved_amount' => 'decimal:4',
            'internal_executed_amount' => 'decimal:4',
            'external_executed_amount' => 'decimal:4',
            'remaining_target_amount' => 'decimal:4',
            'original_display_quantity' => 'decimal:4',
            'execution_anchor_date' => 'date',
            'first_eligible_execution_date' => 'date',
            'second_eligible_execution_date' => 'date',
            'execution_expires_at' => 'datetime',
            'superseded_at' => 'datetime',
            'superseded_by_id' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(TradingStrategyVersion::class, 'strategy_version_id');
    }

    public function owningStrategyId(): ?int
    {
        if ($this->relationLoaded('strategyVersion')) {
            $id = $this->strategyVersion?->strategy_id;

            return $id !== null ? (int) $id : null;
        }

        if ($this->strategy_version_id === null) {
            return null;
        }

        $id = TradingStrategyVersion::query()
            ->where('id', $this->strategy_version_id)
            ->value('strategy_id');

        return $id !== null ? (int) $id : null;
    }

    public function evaluationResult(): BelongsTo
    {
        return $this->belongsTo(EvaluationResult::class, 'evaluation_result_id');
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'security_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TosNotification::class, 'recommendation_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(TradingOrder::class, 'recommendation_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RecommendationReview::class, 'recommendation_id');
    }

    public function capitalRequests(): HasMany
    {
        return $this->hasMany(CapitalRequest::class, 'recommendation_id');
    }

    public function executedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'executed_transaction_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recommendation_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForProfile(Builder $query, PortfolioProfile|int $profile): Builder
    {
        $id = $profile instanceof PortfolioProfile ? $profile->id : $profile;

        return $query->where('profile_id', $id);
    }

    /**
     * Approved recommendations awaiting ledger fill (includes BC `accepted` rows).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingExecution(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_EXECUTION,
            self::STATUS_ACCEPTED,
        ]);
    }

    /**
     * Actionable recommendations still awaiting Approve / Reject / Defer.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpenForReview(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_DEFERRED,
        ]);
    }

    /**
     * Buy recommendations with cash reserved at approval time.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithCashReservation(Builder $query): Builder
    {
        return $query->where('reservation_status', self::RESERVATION_RESERVED);
    }

    /**
     * Actionable portfolio actions plus legacy BUY/SELL recommendation_type values.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActionableTypes(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (self::ACTIONABLE_ACTIONS as $i => $t) {
                $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                $q->{$method}('UPPER(recommendation_type) = ?', [$t]);
            }
            $q->orWhereRaw('UPPER(recommendation_type) = ?', ['BUY'])
                ->orWhereRaw('UPPER(recommendation_type) = ?', ['SELL']);
        });
    }

    /**
     * Open lifecycle statuses shown in default recommendation lists / preview lookup.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpenList(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_LIST_STATUSES);
    }

    /**
     * Pre-terminal rows cancelled or expired by generation / maintenance jobs.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStaleOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::STALE_OPEN_STATUSES);
    }

    public function actionUpper(): string
    {
        return strtoupper((string) $this->recommendation_type);
    }

    public function portfolioAction(): string
    {
        return match ($this->actionUpper()) {
            'BUY' => 'OPEN_POSITION',
            'SELL' => 'EXIT_POSITION',
            'HOLD' => 'HOLD_POSITION',
            default => $this->actionUpper(),
        };
    }

    /**
     * F137 PD-05 — map V1 portfolio actions / legacy types to the public canonical enum.
     * Does not mutate stored recommendation_type.
     *
     * @return 'BUY'|'SELL'|'HOLD_POSITION'|'WATCH'
     */
    public static function toF137Canonical(string $v1Action): string
    {
        $action = strtoupper(trim($v1Action));

        return match ($action) {
            self::ACTION_OPEN_POSITION, self::ACTION_INCREASE_POSITION, 'BUY' => 'BUY',
            self::ACTION_EXIT_POSITION, self::ACTION_REDUCE_POSITION, 'SELL' => 'SELL',
            self::ACTION_HOLD_POSITION, 'HOLD' => 'HOLD_POSITION',
            self::ACTION_WATCH => 'WATCH',
            default => throw new \InvalidArgumentException("Non-canonical V1 recommendation action for F137: {$v1Action}"),
        };
    }

    public function asF137Canonical(): string
    {
        return self::toF137Canonical($this->portfolioAction());
    }

    public function uiLabel(): string
    {
        $action = $this->portfolioAction();

        return self::UI_LABELS[$action] ?? $action;
    }

    public function isActionable(): bool
    {
        return in_array($this->portfolioAction(), self::ACTIONABLE_ACTIONS, true);
    }

    public function isInformational(): bool
    {
        return in_array($this->portfolioAction(), self::INFORMATIONAL_ACTIONS, true);
    }

    public function category(): string
    {
        return $this->isActionable() ? 'actionable' : 'informational';
    }

    public function orderSide(): ?string
    {
        return match ($this->portfolioAction()) {
            'OPEN_POSITION', 'INCREASE_POSITION' => 'buy',
            'REDUCE_POSITION', 'EXIT_POSITION' => 'sell',
            default => null,
        };
    }

    public function isPendingExecution(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_EXECUTION,
            self::STATUS_ACCEPTED, // BC pre-migration rows
        ], true);
    }

    public function isImmutable(): bool
    {
        return in_array($this->status, [
            self::STATUS_EXECUTED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_ARCHIVED,
            self::STATUS_SUPERSEDED,
        ], true);
    }

    /** Still awaiting Approve / Reject / Defer. */
    public function canBeReviewed(): bool
    {
        if (! $this->isActionable()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_DEFERRED,
        ], true);
    }

    /**
     * Undo Approve / Reject / Defer / Cancelled (execution) → pending_review.
     */
    public function canReopenForReview(): bool
    {
        if (! $this->isActionable()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_PENDING_EXECUTION,
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_DEFERRED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /** Manual or future broker execution may proceed. */
    public function canExecuteManually(): bool
    {
        return $this->isActionable() && $this->isPendingExecution();
    }

    /** @deprecated Use canExecuteManually() */
    public function canCreateOrder(): bool
    {
        return $this->canExecuteManually();
    }

    public function canCancelExecution(): bool
    {
        return $this->canExecuteManually();
    }

    public function reviewStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_REVIEW => 'pending_review',
            self::STATUS_PENDING_EXECUTION, self::STATUS_ACCEPTED, self::STATUS_EXECUTED,
            self::STATUS_CANCELLED, self::STATUS_EXPIRED => 'approved',
            self::STATUS_SUPERSEDED => 'superseded',
            self::STATUS_REJECTED => 'rejected',
            self::STATUS_DEFERRED => 'deferred',
            self::STATUS_PUBLISHED => 'published',
            default => (string) $this->status,
        };
    }

    public function executionStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_EXECUTION, self::STATUS_ACCEPTED => 'pending',
            self::STATUS_EXECUTED => 'executed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_EXPIRED => 'expired',
            self::STATUS_SUPERSEDED => 'superseded',
            self::STATUS_PENDING_REVIEW, self::STATUS_DEFERRED, self::STATUS_REJECTED => 'awaiting_approval',
            self::STATUS_PUBLISHED => 'not_applicable',
            default => 'not_applicable',
        };
    }

    public function suggestedQuantity(): ?float
    {
        $plan = is_array($this->execution_plan) ? $this->execution_plan : [];
        if (isset($plan['suggested_quantity'])) {
            return (float) $plan['suggested_quantity'];
        }
        if ($this->suggested_position_size !== null) {
            return (float) $this->suggested_position_size;
        }

        return null;
    }

    public function suggestedInvestmentAmount(): ?float
    {
        if ($this->suggested_allocation_amount !== null) {
            return (float) $this->suggested_allocation_amount;
        }
        $plan = is_array($this->execution_plan) ? $this->execution_plan : [];
        if (isset($plan['suggested_investment_amount'])) {
            return (float) $plan['suggested_investment_amount'];
        }
        $qty = $this->suggestedQuantity();
        $price = $this->reference_price !== null ? (float) $this->reference_price : null;
        if ($qty === null || $price === null) {
            return null;
        }

        return round($qty * $price, 2);
    }

    public function requiresCashReservation(): bool
    {
        return in_array($this->orderSide(), ['buy'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function capitalAllocationMeta(): ?array
    {
        $plan = is_array($this->execution_plan) ? $this->execution_plan : [];
        if (isset($plan['capital_allocation']) && is_array($plan['capital_allocation'])) {
            return $plan['capital_allocation'];
        }
        $evidence = is_array($this->evidence) ? $this->evidence : [];
        if (isset($evidence['capital_allocation']) && is_array($evidence['capital_allocation'])) {
            return $evidence['capital_allocation'];
        }

        return null;
    }

    public function capitalAllocationStatus(): ?string
    {
        $meta = $this->capitalAllocationMeta();
        if ($meta === null || ! isset($meta['status'])) {
            return null;
        }

        return (string) $meta['status'];
    }

    public function capitalTargetAmount(): ?float
    {
        $meta = $this->capitalAllocationMeta();
        if ($meta !== null && isset($meta['target_amount'])) {
            return (float) $meta['target_amount'];
        }
        $plan = is_array($this->execution_plan) ? $this->execution_plan : [];
        if (isset($plan['target_investment_amount'])) {
            return (float) $plan['target_investment_amount'];
        }

        return null;
    }

    /**
     * V3 §13.2 / §28.6 primary exit attribution (strategy_exit|stop_loss|trailing_stop|horizon_expiry).
     */
    public function primaryExitReason(): ?string
    {
        $plan = is_array($this->execution_plan) ? $this->execution_plan : [];
        if (! empty($plan['primary_exit_reason']) && is_string($plan['primary_exit_reason'])) {
            return $plan['primary_exit_reason'];
        }
        if (isset($plan['exit_attribution']['primary_reason']) && is_string($plan['exit_attribution']['primary_reason'])) {
            return $plan['exit_attribution']['primary_reason'];
        }
        $evidence = is_array($this->evidence) ? $this->evidence : [];
        if (isset($evidence['exit_attribution']['primary_reason']) && is_string($evidence['exit_attribution']['primary_reason'])) {
            return $evidence['exit_attribution']['primary_reason'];
        }

        return null;
    }

    public function ownAllocatedAmount(): ?float
    {
        $meta = $this->capitalAllocationMeta();
        if ($meta !== null && isset($meta['allocated_amount'])) {
            return (float) $meta['allocated_amount'];
        }

        return $this->suggestedInvestmentAmount();
    }

    public static function initialStatusForAction(string $action): string
    {
        $normalized = match (strtoupper($action)) {
            'BUY' => 'OPEN_POSITION',
            'SELL' => 'EXIT_POSITION',
            'HOLD' => 'HOLD_POSITION',
            default => strtoupper($action),
        };

        if (in_array($normalized, self::INFORMATIONAL_ACTIONS, true)) {
            return self::STATUS_PUBLISHED;
        }

        return self::STATUS_PENDING_REVIEW;
    }

    public static function normalizeReviewDecision(string $decision): string
    {
        $decision = strtolower(trim($decision));
        if ($decision === 'accepted') {
            return self::DECISION_APPROVED;
        }

        return $decision;
    }
}
