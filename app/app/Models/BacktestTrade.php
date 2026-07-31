<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestTrade extends Model
{
    protected $table = 'portfolio_backtest_trades';

    protected $fillable = [
        'backtest_run_id',
        'stock_id',
        'symbol',
        'buy_date',
        'sell_date',
        'holding_days',
        'buy_price',
        'sell_price',
        'quantity',
        'profit_loss',
        'return_pct',
        'cagr',
        'exit_reason',
        'is_open',
    ];

    protected $casts = [
        'buy_date' => 'date',
        'sell_date' => 'date',
        'buy_price' => 'float',
        'sell_price' => 'float',
        'quantity' => 'float',
        'profit_loss' => 'float',
        'return_pct' => 'float',
        'cagr' => 'float',
        'is_open' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
