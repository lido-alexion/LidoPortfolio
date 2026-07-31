<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestSnapshot extends Model
{
    protected $table = 'portfolio_backtest_snapshots';

    protected $fillable = [
        'backtest_run_id',
        'snapshot_date',
        'cash',
        'invested_value',
        'portfolio_value',
        'realized_profit',
        'unrealized_profit',
        'drawdown_pct',
        'holdings_count',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'cash' => 'float',
        'invested_value' => 'float',
        'portfolio_value' => 'float',
        'realized_profit' => 'float',
        'unrealized_profit' => 'float',
        'drawdown_pct' => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
