<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PortfolioReplayRun extends Model
{
    protected $table = 'portfolio_replay_runs';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->run_uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'checkpoint_date' => 'date',
            'starting_cash' => 'decimal:4', 'adverse_slippage_percent' => 'decimal:4',
            'pinned_world' => 'array', 'starting_state' => 'array', 'readiness' => 'array', 'results' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }
}
