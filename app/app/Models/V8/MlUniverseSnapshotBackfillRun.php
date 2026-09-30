<?php

namespace App\Models\V8;

use Illuminate\Database\Eloquent\Model;

class MlUniverseSnapshotBackfillRun extends Model
{
    protected $table = 'stox_ml_universe_snapshot_backfill_runs';

    protected $fillable = [
        'universe_key', 'source', 'requested_dates', 'processed_dates',
        'failed_dates', 'status', 'last_error', 'started_at', 'completed_at',
        'retry_counts', 'acceptance',
    ];

    protected function casts(): array
    {
        return [
            'acceptance' => 'array',
            'requested_dates' => 'array',
            'processed_dates' => 'array',
            'failed_dates' => 'array',
            'retry_counts' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
