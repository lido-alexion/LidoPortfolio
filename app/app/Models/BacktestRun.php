<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    public const STAGE_PREPARING = 'PREPARING';

    public const STAGE_SIMULATING_DAYS = 'SIMULATING_DAYS';

    public const STAGE_GENERATING_STATISTICS = 'GENERATING_STATISTICS';

    public const STAGE_GENERATING_REPORT = 'GENERATING_REPORT';

    public const STAGE_COMPLETED = 'COMPLETED';

    public const STAGE_FAILED = 'FAILED';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'portfolio_backtest_runs';

    protected $fillable = [
        'profile_id',
        'user_id',
        'strategy_id',
        'strategy_version_id',
        'strategy_name',
        'strategy_version_number',
        'entry_screener_versions_json',
        'exit_screener_versions_json',
        'name',
        'notes',
        'tags_json',
        'range_key',
        'from_date',
        'to_date',
        'initial_capital',
        'status',
        'stage',
        'processed_days',
        'total_days',
        'progress_pct',
        'current_date',
        'session_token',
        'context_json',
        'statistics_json',
        'error_message',
        'started_at',
        'completed_at',
        'execution_seconds',
    ];

    protected $casts = [
        'entry_screener_versions_json' => 'array',
        'exit_screener_versions_json' => 'array',
        'tags_json' => 'array',
        'context_json' => 'array',
        'statistics_json' => 'array',
        'from_date' => 'date',
        'to_date' => 'date',
        'current_date' => 'date',
        'initial_capital' => 'float',
        'progress_pct' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BacktestTransaction::class, 'backtest_run_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class, 'backtest_run_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(BacktestSnapshot::class, 'backtest_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
