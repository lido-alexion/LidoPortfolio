<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundamentalUpdateRun extends Model
{
    protected $table = 'stox_fundamental_update_runs';

    protected $fillable = [
        'trigger',
        'scope',
        'status',
        'requested',
        'processed',
        'succeeded',
        'failed',
        'skipped',
        'stats_json',
        'last_error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested' => 'integer',
            'processed' => 'integer',
            'succeeded' => 'integer',
            'failed' => 'integer',
            'skipped' => 'integer',
            'stats_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(FundamentalUpdateJob::class, 'run_id');
    }
}
