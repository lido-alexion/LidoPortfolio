<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketAnalyticsSnapshot extends Model
{
    protected $table = 'portfolio_tos_market_analytics';

    protected $fillable = [
        'benchmark_stock_id',
        'as_of_date',
        'market_phase',
        'sentiment_score',
        'sentiment_label',
        'payload_json',
        'explainability_json',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'benchmark_stock_id' => 'integer',
            'as_of_date' => 'date',
            'sentiment_score' => 'float',
            'payload_json' => 'array',
            'explainability_json' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function benchmark(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'benchmark_stock_id');
    }
}
