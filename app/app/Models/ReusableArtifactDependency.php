<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReusableArtifactDependency extends Model
{
    protected $table = 'portfolio_reusable_artifact_dependencies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_version_id' => 'integer',
            'target_artifact_version_id' => 'integer',
            'required' => 'boolean',
        ];
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'source_version_id');
    }

    public function targetVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'target_artifact_version_id');
    }
}
