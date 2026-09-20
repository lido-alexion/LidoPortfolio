<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlDriftCheck extends Model
{
    protected $table = 'stox_ml_drift_checks';

    protected $fillable = ['model_version_id', 'window_months', 'status', 'metrics', 'warnings', 'checked_at'];

    protected function casts(): array
    {
        return ['window_months' => 'integer', 'metrics' => 'array', 'warnings' => 'array', 'checked_at' => 'datetime'];
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'model_version_id');
    }
}
