<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingOrder extends Model
{
    protected $table = 'portfolio_tos_orders';

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'profile_id',
        'recommendation_id',
        'security_id',
        'side',
        'quantity',
        'limit_price',
        'order_type',
        'notes',
        'status',
        'executed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'limit_price' => 'decimal:4',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(TradingRecommendation::class, 'recommendation_id');
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'security_id');
    }

    public function orderTransactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class, 'order_id');
    }
}
