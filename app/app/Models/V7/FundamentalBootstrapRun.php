<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundamentalBootstrapRun extends Model
{
    protected $table = 'stox_fundamental_bootstrap_runs';

    protected $fillable = [
        'trigger',
        'scope',
        'status',
        'requested',
        'queued',
        'running',
        'completed',
        'failed',
        'summary_json',
        'last_error',
        'created_by_user_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested' => 'integer',
            'queued' => 'integer',
            'running' => 'integer',
            'completed' => 'integer',
            'failed' => 'integer',
            'summary_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(FundamentalBootstrapJob::class, 'run_id');
    }
}
