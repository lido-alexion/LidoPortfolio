<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Recall Bridge Loan — temporary bridge to fulfil a recall only (DEP-RECALL-BRIDGE).
 * Not a Soft Loan. Cannot fund investments. Cannot itself be recalled.
 * No ₹5,000 multiple. No normal-loan recall cooldown.
 */
class RecallBridgeLoan extends Model
{
    protected $table = 'portfolio_tos_recall_bridge_loans';

    public const STATUS_OUTSTANDING = 'outstanding';

    public const STATUS_PARTIALLY_RETURNED = 'partially_returned';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'profile_id',
        'capital_recall_id',
        'borrower_strategy_id',
        'lender_strategy_id',
        'principal',
        'outstanding',
        'committed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'capital_recall_id' => 'integer',
            'borrower_strategy_id' => 'integer',
            'lender_strategy_id' => 'integer',
            'principal' => 'decimal:4',
            'outstanding' => 'decimal:4',
            'committed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $loan): void {
            CapitalRequest::assertLenderDiffersFromBorrower(
                (int) $loan->lender_strategy_id,
                (int) $loan->borrower_strategy_id,
            );
            if ((int) $loan->lender_strategy_id === (int) $loan->borrower_strategy_id) {
                throw new InvalidArgumentException('Bridge lender must differ from borrower.');
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function capitalRecall(): BelongsTo
    {
        return $this->belongsTo(CapitalRecall::class, 'capital_recall_id');
    }

    public function borrowerStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'borrower_strategy_id');
    }

    public function lenderStrategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'lender_strategy_id');
    }
}
