<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMetric extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_stock_metrics';

    protected $fillable = [
        'stock_id',
        'highest_close',
        'latest_close',
        'stoploss_percent',
        'trailing_stop_price',
        'relative_strength_1m',
        'relative_strength_3m',
        'relative_strength_6m',
        'tracking_active',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'highest_close' => 'decimal:4',
            'latest_close' => 'decimal:4',
            'stoploss_percent' => 'decimal:4',
            'trailing_stop_price' => 'decimal:4',
            'relative_strength_1m' => 'decimal:6',
            'relative_strength_3m' => 'decimal:6',
            'relative_strength_6m' => 'decimal:6',
            'tracking_active' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
