<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * V3 recall of a normal inter-strategy CapitalLoan (spec §6.6–§6.14).
 * Eligible is computed dynamically — not a persisted row state.
 */
class CapitalRecall extends Model
{
    protected $table = 'portfolio_tos_capital_recalls';

    public const KIND_FULL = 'full';

    public const KIND_PARTIAL = 'partial';

    public const STATE_REQUESTED = 'requested';

    public const STATE_IMMEDIATE_SETTLEMENT = 'immediate_settlement';

    public const STATE_PENDING_HELD = 'pending_held';

    public const STATE_LIQUIDATION = 'liquidation';

    public const STATE_SETTLEMENT = 'settlement';

    public const STATE_COMPLETED = 'completed';

    /** States that block a new portfolio-wide recall (DEP-RECALL-FOLLOWUP). */
    public const ACTIVE_STATES = [
        self::STATE_REQUESTED,
        self::STATE_IMMEDIATE_SETTLEMENT,
        self::STATE_PENDING_HELD,
        self::STATE_LIQUIDATION,
        self::STATE_SETTLEMENT,
    ];

    protected $fillable = [
        'profile_id',
        'loan_id',
        'lender_strategy_id',
        'borrower_strategy_id',
        'kind',
        'recall_amount',
        'outstanding_recall_amount',
        'settled_amount',
        'state',
        'requested_at',
        'completed_at',
        'pending_held_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'loan_id' => 'integer',
            'lender_strategy_id' => 'integer',
            'borrower_strategy_id' => 'integer',
            'recall_amount' => 'decimal:4',
            'outstanding_recall_amount' => 'decimal:4',
            'settled_amount' => 'decimal:4',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'pending_held_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE_STATES, true);
    }

    public function isCompleted(): bool
    {
        return $this->state === self::STATE_COMPLETED;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CapitalLoan::class, 'loan_id');
    }

    public function lenderStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'lender_strategy_id');
    }

    public function borrowerStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'borrower_strategy_id');
    }

    public function bridgeLoans(): HasMany
    {
        return $this->hasMany(RecallBridgeLoan::class, 'capital_recall_id');
    }

    public function pendingSaleProceeds(): HasMany
    {
        return $this->hasMany(PendingSaleProceeds::class, 'capital_recall_id');
    }
}
