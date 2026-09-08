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

    public const BROKER_SUBMITTED = 'submitted';

    public const BROKER_OPEN = 'open';

    public const BROKER_PARTIAL = 'partial';

    public const BROKER_FILLED = 'filled';

    public const BROKER_REJECTED = 'rejected';

    public const BROKER_CANCELLED = 'cancelled';

    public const BROKER_UNKNOWN = 'unknown';

    public const IN_FLIGHT_BROKER_STATUSES = [
        self::BROKER_SUBMITTED,
        self::BROKER_OPEN,
        self::BROKER_PARTIAL,
        self::BROKER_UNKNOWN,
    ];

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
        'broker_provider',
        'broker_order_id',
        'broker_status',
        'filled_quantity',
        'average_fill_price',
        'submission_key',
        'execution_decision_id',
        'last_broker_sync_at',
        'reusable_artifact_version_id',
        'artifact_binding_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'limit_price' => 'decimal:4',
            'filled_quantity' => 'decimal:4',
            'average_fill_price' => 'decimal:4',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_broker_sync_at' => 'datetime',
            'reusable_artifact_version_id' => 'integer',
            'artifact_binding_revision_id' => 'integer',
        ];
    }

    public function hasInFlightBrokerOrder(): bool
    {
        return is_string($this->broker_status)
            && in_array($this->broker_status, self::IN_FLIGHT_BROKER_STATUSES, true);
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

    public function reusableArtifactVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'reusable_artifact_version_id');
    }

    public function artifactBindingRevision(): BelongsTo
    {
        return $this->belongsTo(ArtifactBindingRevision::class, 'artifact_binding_revision_id');
    }
}
