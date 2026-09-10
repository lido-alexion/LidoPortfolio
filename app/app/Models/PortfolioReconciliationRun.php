<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class PortfolioReconciliationRun extends Model
{
    protected $table = 'portfolio_reconciliation_runs';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->run_uuid ??= (string) Str::uuid());
        static::updating(fn () => throw new LogicException('Completed reconciliation runs are immutable.'));
        static::deleting(fn () => throw new LogicException('Reconciliation history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'broker_snapshot' => 'array', 'stox_snapshot' => 'array', 'tolerances' => 'array',
            'discrepancies' => 'array', 'unsupported_instruments' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }
}
