<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAdjustmentFactor extends Model
{
    protected $table = 'portfolio_price_adjustment_factors';

    protected $fillable = [
        'stock_id',
        'issue_id',
        'factor_type',
        'action_type',
        'effective_ex_date',
        'applied_ratio',
        'price_divisor',
        'volume_multiplier',
        'is_active',
        'applied_at',
        'reversed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'stock_id' => 'integer',
            'issue_id' => 'integer',
            'effective_ex_date' => 'date',
            'applied_ratio' => 'decimal:6',
            'price_divisor' => 'decimal:6',
            'volume_multiplier' => 'decimal:6',
            'is_active' => 'boolean',
            'applied_at' => 'datetime',
            'reversed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(DataQualityIssue::class, 'issue_id');
    }
}
