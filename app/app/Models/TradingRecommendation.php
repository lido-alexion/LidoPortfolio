<?php

namespace App\Models;

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

    /** Approved but will not be executed (operator cancelled the execution). */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ARCHIVED = 'archived';

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

    public const CANCELLATION_REASONS = [
        'price_moved',
        'market_conditions',
        'funds_unavailable',
        'broker_rejected',
        'executed_outside_system',
        'no_longer_valid',
        'other',
    ];

    public const CANCELLATION_REASON_LABELS = [
        'price_moved' => 'Price moved significantly',
        'market_conditions' => 'Market conditions changed',
        'funds_unavailable' => 'Funds unavailable',
        'broker_rejected' => 'Broker rejected order',
        'executed_outside_system' => 'Executed outside system',
        'no_longer_valid' => 'Recommendation no longer valid',
        'other' => 'Other',
    ];

    /** Portfolio actions that require review then pending execution. */
    public const ACTIONABLE_ACTIONS = [
        'OPEN_POSITION',
        'INCREASE_POSITION',
        'REDUCE_POSITION',
        'EXIT_POSITION',
    ];

    /** Portfolio actions that are auto-published. */
    public const INFORMATIONAL_ACTIONS = [
        'HOLD_POSITION',
        'WATCH',
    ];

    /** @deprecated Use ACTIONABLE_ACTIONS */
    public const ACTIONABLE_TYPES = self::ACTIONABLE_ACTIONS;

    /** @deprecated Use INFORMATIONAL_ACTIONS */
    public const INFORMATIONAL_TYPES = self::INFORMATIONAL_ACTIONS;

    public const UI_LABELS = [
        'OPEN_POSITION' => 'Buy',
        'INCREASE_POSITION' => 'Buy More',
        'REDUCE_POSITION' => 'Sell Partial',
        'EXIT_POSITION' => 'Sell All',
        'HOLD_POSITION' => 'Hold',
        'WATCH' => 'Watch',
        'BUY' => 'Buy',
        'SELL' => 'Sell All',
        'HOLD' => 'Hold',
    ];

    protected $fillable = [
        'profile_id',
        'evaluation_result_id',
        'security_id',
        'recommendation_type',
        'market_opinion',
        'execution_plan',
        'priority',
        'confidence',
        'risk_level',
        'suggested_position_size',
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
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'suggested_position_size' => 'decimal:4',
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
            'executed_transaction_id' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
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

    public function executedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'executed_transaction_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recommendation_id');
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
        $qty = $this->suggestedQuantity();
        $price = $this->reference_price !== null ? (float) $this->reference_price : null;
        if ($qty === null || $price === null) {
            return null;
        }

        return round($qty * $price, 2);
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
