<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holding extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_holdings';

    public const OWNER_UNMANAGED = 'unmanaged';

    protected $fillable = [
        'profile_id',
        'stock_id',
        'strategy_id',
        'owner_key',
        'quantity',
        'avg_buy_price',
        'invested_amount',
        'total_fees',
        'realized_profit',
        'updated_at',
    ];

    protected $attributes = [
        'owner_key' => self::OWNER_UNMANAGED,
    ];

    protected function casts(): array
    {
        return [
            'strategy_id' => 'integer',
            'quantity' => 'decimal:4',
            'avg_buy_price' => 'decimal:4',
            'invested_amount' => 'decimal:4',
            'total_fees' => 'decimal:4',
            'realized_profit' => 'decimal:4',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $holding) {
            if ($holding->owner_key === null || $holding->owner_key === '') {
                $holding->owner_key = self::ownerKeyFor($holding->strategy_id);
            } elseif ($holding->isDirty('strategy_id') && ! $holding->isDirty('owner_key')) {
                $holding->owner_key = self::ownerKeyFor($holding->strategy_id);
            }
        });
    }

    public static function ownerKeyFor(?int $strategyId): string
    {
        return $strategyId !== null && $strategyId > 0
            ? 'strategy:'.$strategyId
            : self::OWNER_UNMANAGED;
    }

    public function isUnmanaged(): bool
    {
        return $this->strategy_id === null || $this->owner_key === self::OWNER_UNMANAGED;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'strategy_id');
    }
}
