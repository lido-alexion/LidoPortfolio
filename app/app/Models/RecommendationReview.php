<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationReview extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_tos_recommendation_reviews';

    protected $fillable = [
        'recommendation_id',
        'user_id',
        'decision',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(TradingRecommendation::class, 'recommendation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
