<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashLedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_cash_ledger_entries';

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_BUY = 'buy';

    public const TYPE_SELL = 'sell';

    /** V4-SPEC-004 special-movement types. Sign determines cash direction. */
    public const TYPE_LOAN = 'loan';

    public const TYPE_RECALL = 'recall';

    public const TYPE_BRIDGE = 'bridge';

    public const SPECIAL_TYPES = [
        self::TYPE_LOAN,
        self::TYPE_RECALL,
        self::TYPE_BRIDGE,
    ];

    public const TYPES = [
        self::TYPE_DEPOSIT,
        self::TYPE_WITHDRAWAL,
        self::TYPE_ADJUSTMENT,
        self::TYPE_BUY,
        self::TYPE_SELL,
        self::TYPE_LOAN,
        self::TYPE_RECALL,
        self::TYPE_BRIDGE,
    ];

    protected $fillable = [
        'profile_id',
        'entry_type',
        'amount',
        'balance_after',
        'reason',
        'entry_date',
        'transaction_id',
        'recommendation_id',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'entry_date' => 'date',
            'transaction_id' => 'integer',
            'recommendation_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(TradingRecommendation::class, 'recommendation_id');
    }
}
