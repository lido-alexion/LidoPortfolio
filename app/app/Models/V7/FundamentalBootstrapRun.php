<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
        'idempotency_key',
        'request_snapshot',
        'trigger_actor',
        'operator_reason',
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
            'request_snapshot' => 'array',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(FundamentalBootstrapJob::class, 'run_id');
    }

    public function scopeOpsManaged(Builder $query): Builder
    {
        return $query
            ->where('request_snapshot->managed_version', \App\Services\Fundamentals\FundamentalBootstrapService::OPS_MANAGED_VERSION)
            ->where('request_snapshot->origin', 'admin_fundamentals_bootstrap_operations');
    }
}
