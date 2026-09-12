<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MlTrainingRun extends Model
{
    protected $table = 'stox_ml_training_runs';

    protected $fillable = [
        'horizon',
        'status',
        'cutoff_date',
        'configuration',
        'metrics',
        'baselines',
        'selected_features',
        'failure',
        'requested_by',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'configuration' => 'array',
            'metrics' => 'array',
            'baselines' => 'array',
            'selected_features' => 'array',
            'failure' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function modelVersion(): HasOne
    {
        return $this->hasOne(MlModelVersion::class, 'training_run_id');
    }
}
