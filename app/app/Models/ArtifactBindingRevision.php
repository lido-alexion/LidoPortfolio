<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ArtifactBindingRevision extends Model
{
    protected $table = 'portfolio_artifact_binding_revisions';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Artifact binding revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Artifact binding revisions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'binding_id' => 'integer',
            'revision_number' => 'integer',
            'artifact_version_id' => 'integer',
            'settings_json' => 'array',
            'usability_reasons_json' => 'array',
            'activated_by_user_id' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(ArtifactBinding::class, 'binding_id');
    }

    public function artifactVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'artifact_version_id');
    }
}
