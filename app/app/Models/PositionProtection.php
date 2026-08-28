<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionProtection extends Model
{
    protected $table = 'portfolio_tos_position_protections';

    public const TYPE_TARGET = 'target';

    public const TYPE_STOP = 'stop';

    public const STATE_PENDING = 'pending';

    public const STATE_ACTIVE = 'active';

    public const STATE_SYNCHRONIZING = 'synchronizing';

    public const STATE_NEEDS_ATTENTION = 'needs_attention';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_RECONCILED = 'reconciled';

    public const BROKER_SUBMITTED = 'submitted';

    public const BROKER_ACTIVE = 'active';

    public const BROKER_TRIGGERED = 'triggered';

    public const BROKER_CANCELLED = 'cancelled';

    public const BROKER_REJECTED = 'rejected';

    public const BROKER_UNKNOWN = 'unknown';

    public const TYPES = [self::TYPE_TARGET, self::TYPE_STOP];

    public const OPEN_STATES = [
        self::STATE_PENDING,
        self::STATE_ACTIVE,
        self::STATE_SYNCHRONIZING,
        self::STATE_NEEDS_ATTENTION,
    ];

    protected $fillable = [
        'profile_id',
        'holding_id',
        'stock_id',
        'strategy_id',
        'owner_key',
        'protection_type',
        'state',
        'trigger_price',
        'quantity',
        'broker_gtt_id',
        'broker_status',
        'submission_key',
        'trading_order_id',
        'last_applied_fill_qty',
        'sync_deferred',
        'retry_count',
        'last_error',
        'last_sync_reason',
        'last_broker_sync_at',
        'needs_attention_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_price' => 'decimal:4',
            'quantity' => 'decimal:4',
            'last_applied_fill_qty' => 'decimal:4',
            'sync_deferred' => 'boolean',
            'retry_count' => 'integer',
            'last_broker_sync_at' => 'datetime',
            'needs_attention_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN_STATES, true);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class, 'holding_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'strategy_id');
    }

    public function tradingOrder(): BelongsTo
    {
        return $this->belongsTo(TradingOrder::class, 'trading_order_id');
    }

    public function scopeForProfile(Builder $query, PortfolioProfile $profile): Builder
    {
        return $query->where('profile_id', $profile->id);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', self::OPEN_STATES);
    }
}
