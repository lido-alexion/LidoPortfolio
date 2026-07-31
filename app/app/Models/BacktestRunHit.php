<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transient screener hit for an in-progress backtest run.
 * Deleted when the run completes, fails, or is deleted.
 */
class BacktestRunHit extends Model
{
    public const ROLE_ENTRY = 'entry';

    public const ROLE_EXIT = 'exit';

    protected $table = 'portfolio_backtest_run_hits';

    protected $fillable = [
        'backtest_run_id',
        'screener_id',
        'role',
        'as_of_date',
        'stock_id',
    ];

    protected $casts = [
        'as_of_date' => 'date',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
