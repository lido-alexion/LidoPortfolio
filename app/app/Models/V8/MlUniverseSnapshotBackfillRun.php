<?php

namespace App\Models\V8;

use Illuminate\Database\Eloquent\Model;

class MlUniverseSnapshotBackfillRun extends Model
{
    protected $table = 'stox_ml_universe_snapshot_backfill_runs';

    protected $fillable = [
        'universe_key', 'source', 'requested_dates', 'processed_dates',
        'failed_dates', 'status', 'last_error', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_dates' => 'array',
            'processed_dates' => 'array',
            'failed_dates' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
