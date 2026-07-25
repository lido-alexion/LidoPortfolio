<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingRecommendation extends Model
{
    protected $table = 'portfolio_tos_recommendations';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const REVIEW_DECISIONS = [
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_DEFERRED,
    ];

    protected $fillable = [
        'profile_id',
        'evaluation_result_id',
        'security_id',
        'recommendation_type',
        'priority',
        'confidence',
        'risk_level',
        'suggested_position_size',
        'reference_price',
        'status',
        'evidence',
        'failed_checks',
        'version',
        'expires_at',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'suggested_position_size' => 'decimal:4',
            'reference_price' => 'decimal:4',
            'evidence' => 'array',
            'failed_checks' => 'array',
            'expires_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function evaluationResult(): BelongsTo
    {
        return $this->belongsTo(EvaluationResult::class, 'evaluation_result_id');
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'security_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TosNotification::class, 'recommendation_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(TradingOrder::class, 'recommendation_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RecommendationReview::class, 'recommendation_id');
    }

    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_EXECUTED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true);
    }

    public function canBeReviewed(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_DEFERRED,
            self::STATUS_ACCEPTED,
        ], true);
    }

    public function canCreateOrder(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }
}
