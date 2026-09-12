<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlModelVersion extends Model
{
    protected $table = 'stox_ml_model_versions';

    protected $fillable = [
        'training_run_id',
        'horizon',
        'version',
        'status',
        'model_family',
        'training_cutoff_date',
        'feature_set',
        'preprocessing',
        'label_definition',
        'benchmark_mapping',
        'hyperparameters',
        'evaluation_metrics',
        'promotion_thresholds',
        'audit_metadata',
        'promoted_at',
        'promoted_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'training_cutoff_date' => 'date',
            'feature_set' => 'array',
            'preprocessing' => 'array',
            'label_definition' => 'array',
            'benchmark_mapping' => 'array',
            'hyperparameters' => 'array',
            'evaluation_metrics' => 'array',
            'promotion_thresholds' => 'array',
            'audit_metadata' => 'array',
            'promoted_at' => 'datetime',
        ];
    }

    public function trainingRun(): BelongsTo
    {
        return $this->belongsTo(MlTrainingRun::class, 'training_run_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(MlPrediction::class, 'model_version_id');
    }
}
