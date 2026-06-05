<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPrice extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_stock_prices';

    protected $fillable = [
        'stock_id',
        'price_date',
        'open_price',
        'high_price',
        'low_price',
        'close_price',
        'adjusted_close_price',
        'volume',
        'provider_source',
        'data_source',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'price_date' => 'date',
            'open_price' => 'decimal:4',
            'high_price' => 'decimal:4',
            'low_price' => 'decimal:4',
            'close_price' => 'decimal:4',
            'adjusted_close_price' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function getProviderSourceAttribute(?string $value): string
    {
        return $value
            ?? $this->attributes['data_source']
            ?? 'unknown';
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
