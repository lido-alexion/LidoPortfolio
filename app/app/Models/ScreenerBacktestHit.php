<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenerBacktestHit extends Model
{
    protected $table = 'portfolio_screener_backtest_hits';

    protected $fillable = [
        'backtest_id',
        'as_of_date',
        'stock_id',
        'symbol',
        'exchange',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'backtest_id' => 'integer',
            'stock_id' => 'integer',
            'as_of_date' => 'date',
        ];
    }

    public function backtest(): BelongsTo
    {
        return $this->belongsTo(ScreenerBacktest::class, 'backtest_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }
}
