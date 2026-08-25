<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoldingAdoption extends Model
{
    protected $table = 'portfolio_holding_adoptions';

    protected $fillable = [
        'profile_id',
        'holding_id',
        'stock_id',
        'from_owner_key',
        'to_strategy_id',
        'to_owner_key',
        'user_id',
        'attribution_recommendation_id',
        'target_amount',
        'idempotent',
        'evidence_json',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'holding_id' => 'integer',
            'stock_id' => 'integer',
            'to_strategy_id' => 'integer',
            'user_id' => 'integer',
            'attribution_recommendation_id' => 'integer',
            'target_amount' => 'decimal:4',
            'idempotent' => 'boolean',
            'evidence_json' => 'array',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class, 'holding_id');
    }
}
