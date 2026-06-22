<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'portfolio_sync_runs';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'stocks_processed',
        'failures',
        'skipped',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'run_id');
    }
}
