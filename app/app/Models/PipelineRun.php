<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineRun extends Model
{
    protected $table = 'portfolio_tos_pipeline_runs';

    protected $fillable = [
        'profile_id',
        'status',
        'stages_json',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'stages_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }
}
