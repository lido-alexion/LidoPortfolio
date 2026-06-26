<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holding extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_holdings';

    protected $fillable = [
        'profile_id',
        'stock_id',
        'quantity',
        'avg_buy_price',
        'invested_amount',
        'total_fees',
        'realized_profit',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'avg_buy_price' => 'decimal:4',
            'invested_amount' => 'decimal:4',
            'total_fees' => 'decimal:4',
            'realized_profit' => 'decimal:4',
            'updated_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
