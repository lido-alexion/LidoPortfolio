<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    protected $table = 'portfolio_tos_evaluation_runs';

    protected $fillable = [
        'profile_id',
        'discovery_run_id',
        'status',
        'started_at',
        'completed_at',
        'stats_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'stats_json' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(EvaluationResult::class, 'evaluation_run_id');
    }
}
