<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscoveryRun extends Model
{
    protected $table = 'portfolio_tos_discovery_runs';

    protected $fillable = [
        'profile_id',
        'dataset_version',
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

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'discovery_run_id');
    }
}
