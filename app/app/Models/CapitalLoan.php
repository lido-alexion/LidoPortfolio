<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Committed inter-strategy loan receivable/obligation (V3 spec §28.5).
 * Not a holding-ownership split. Persistence only in WS4 Step 1.
 */
class CapitalLoan extends Model
{
    protected $table = 'portfolio_tos_loans';

    public const STATUS_OUTSTANDING = 'outstanding';

    public const STATUS_PARTIALLY_RETURNED = 'partially_returned';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'profile_id',
        'capital_request_id',
        'borrower_strategy_id',
        'lender_strategy_id',
        'principal',
        'outstanding',
        'committed_at',
        'min_recall_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'capital_request_id' => 'integer',
            'borrower_strategy_id' => 'integer',
            'lender_strategy_id' => 'integer',
            'principal' => 'decimal:4',
            'outstanding' => 'decimal:4',
            'committed_at' => 'datetime',
            'min_recall_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $loan): void {
            CapitalRequest::assertLenderDiffersFromBorrower(
                (int) $loan->lender_strategy_id,
                (int) $loan->borrower_strategy_id,
            );
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function capitalRequest(): BelongsTo
    {
        return $this->belongsTo(CapitalRequest::class, 'capital_request_id');
    }

    public function borrowerStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'borrower_strategy_id');
    }

    public function lenderStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'lender_strategy_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(CapitalLoanReturn::class, 'loan_id');
    }
}
