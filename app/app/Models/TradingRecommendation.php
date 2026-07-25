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

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ARCHIVED = 'archived';

    public const REVIEW_DECISIONS = [
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_DEFERRED,
    ];

    /** Audit-only decision when a review is undone. */
    public const DECISION_REOPENED = 'reopened';

    /** Portfolio actions that may create orders after Accept. */
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

    /** @deprecated Use ACTIONABLE_ACTIONS — kept for older callers/tests. */
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
        // Legacy
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

    public function actionUpper(): string
    {
        return strtoupper((string) $this->recommendation_type);
    }

    /** Normalized portfolio action (maps legacy BUY/SELL/HOLD). */
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

    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_EXECUTED, self::STATUS_CANCELLED, self::STATUS_ARCHIVED], true);
    }

    public function canBeReviewed(): bool
    {
        if (! $this->isActionable()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_DEFERRED,
            self::STATUS_ACCEPTED,
        ], true);
    }

    /**
     * Undo Accept / Reject / Defer back to pending_review (not available after execute).
     */
    public function canReopenForReview(): bool
    {
        if (! $this->isActionable()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_DEFERRED,
        ], true);
    }

    public function canCreateOrder(): bool
    {
        if (! $this->isActionable()) {
            return false;
        }

        return $this->status === self::STATUS_ACCEPTED;
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

    /** @deprecated use initialStatusForAction */
    public static function initialStatusForType(string $type): string
    {
        return self::initialStatusForAction($type);
    }
}
