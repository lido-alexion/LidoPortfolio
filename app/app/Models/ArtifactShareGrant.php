<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactShareGrant extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'portfolio_artifact_share_grants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'artifact_version_id' => 'integer',
            'owner_user_id' => 'integer',
            'recipient_user_id' => 'integer',
            'dependency_version_ids_json' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function artifactVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'artifact_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
