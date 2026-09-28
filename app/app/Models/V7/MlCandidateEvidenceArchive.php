<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlCandidateEvidenceArchive extends Model
{
    protected $table = 'stox_ml_candidate_evidence_archives';

    protected $fillable = [
        'model_version_id', 'horizon', 'model_version', 'feature_set_version',
        'dataset_version', 'artifact_path', 'artifact_sha256', 'evidence_sha256',
        'evidence', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'model_version' => 'integer',
            'evidence' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'model_version_id');
    }
}
