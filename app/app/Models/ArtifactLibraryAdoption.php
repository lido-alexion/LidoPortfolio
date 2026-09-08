<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactLibraryAdoption extends Model
{
    protected $table = 'portfolio_artifact_library_adoptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'artifact_version_id' => 'integer',
            'share_grant_id' => 'integer',
            'provenance_json' => 'array',
            'adopted_at' => 'datetime',
        ];
    }

    public function artifactVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'artifact_version_id');
    }

    public function shareGrant(): BelongsTo
    {
        return $this->belongsTo(ArtifactShareGrant::class, 'share_grant_id');
    }
}
