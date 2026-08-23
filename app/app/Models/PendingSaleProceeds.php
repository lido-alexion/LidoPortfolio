<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proceeds from stock sale awaiting broker settlement (~1 calendar day).
 * Sale execution does not immediately create available cash (DEP-SALE-PROCEEDS).
 * `amount` = actual proceeds that will be applied when available.
 */
class PendingSaleProceeds extends Model
{
    protected $table = 'portfolio_tos_pending_sale_proceeds';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_APPLIED = 'applied';

    public const OBLIGATION_RECALL = 'recall';

    public const OBLIGATION_BRIDGE = 'bridge';

    protected $fillable = [
        'profile_id',
        'strategy_id',
        'capital_recall_id',
        'obligation_type',
        'recall_bridge_loan_id',
        'transaction_id',
        'amount',
        'expected_amount',
        'required_settlement_amount',
        'target_liquidation_value',
        'sale_buffer_amount',
        'sold_at',
        'available_at',
        'status',
        'applied_at',
        'cash_released_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'strategy_id' => 'integer',
            'capital_recall_id' => 'integer',
            'recall_bridge_loan_id' => 'integer',
            'transaction_id' => 'integer',
            'amount' => 'decimal:4',
            'expected_amount' => 'decimal:4',
            'required_settlement_amount' => 'decimal:4',
            'target_liquidation_value' => 'decimal:4',
            'sale_buffer_amount' => 'decimal:4',
            'sold_at' => 'datetime',
            'available_at' => 'datetime',
            'applied_at' => 'datetime',
            'cash_released_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'strategy_id');
    }

    public function capitalRecall(): BelongsTo
    {
        return $this->belongsTo(CapitalRecall::class, 'capital_recall_id');
    }

    public function recallBridgeLoan(): BelongsTo
    {
        return $this->belongsTo(RecallBridgeLoan::class, 'recall_bridge_loan_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
