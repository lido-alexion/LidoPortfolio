<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestTransaction extends Model
{
    protected $table = 'portfolio_backtest_transactions';

    protected $fillable = [
        'backtest_run_id',
        'trade_date',
        'stock_id',
        'symbol',
        'side',
        'quantity',
        'price',
        'value',
        'reason',
        'recommendation',
        'meta_json',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'quantity' => 'float',
        'price' => 'float',
        'value' => 'float',
        'meta_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
