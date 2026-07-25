<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TosNotification extends Model
{
    protected $table = 'portfolio_tos_notifications';

    protected $fillable = [
        'profile_id',
        'recommendation_id',
        'notification_type',
        'channel',
        'recipient',
        'payload',
        'status',
        'idempotency_key',
        'attempt_count',
        'last_error',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
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
}
