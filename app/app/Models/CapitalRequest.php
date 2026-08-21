<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

/**
 * Inter-strategy capital request (V3 spec §28.5). Persistence only in WS4 Step 1.
 */
class CapitalRequest extends Model
{
    protected $table = 'portfolio_tos_capital_requests';

    public const STATUS_DISPLAYED = 'displayed';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVALIDATION_FAILED = 'revalidation_failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Open funding requests that still represent the unresolved remainder. */
    public const ACTIVE_FUNDING_STATUSES = [
        self::STATUS_DISPLAYED,
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_REVALIDATION_FAILED,
        self::STATUS_COMMITTED,
    ];

    protected $fillable = [
        'profile_id',
        'borrower_strategy_id',
        'lender_strategy_id',
        'recommendation_id',
        'amount',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'borrower_strategy_id' => 'integer',
            'lender_strategy_id' => 'integer',
            'recommendation_id' => 'integer',
            'approved_by' => 'integer',
            'amount' => 'decimal:4',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            self::assertLenderDiffersFromBorrower(
                $request->lender_strategy_id !== null ? (int) $request->lender_strategy_id : null,
                (int) $request->borrower_strategy_id,
            );
        });
    }

    public static function assertLenderDiffersFromBorrower(?int $lenderStrategyId, int $borrowerStrategyId): void
    {
        if ($lenderStrategyId !== null && $lenderStrategyId === $borrowerStrategyId) {
            throw new InvalidArgumentException('Lender strategy must differ from borrower strategy.');
        }
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function borrowerStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'borrower_strategy_id');
    }

    public function lenderStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'lender_strategy_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(TradingRecommendation::class, 'recommendation_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(CapitalLoan::class, 'capital_request_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(CapitalLoanReturn::class, 'capital_request_id');
    }

    public function isApprovable(): bool
    {
        return in_array($this->status, [
            self::STATUS_DISPLAYED,
            self::STATUS_AWAITING_APPROVAL,
            self::STATUS_REVALIDATION_FAILED,
        ], true);
    }
}
