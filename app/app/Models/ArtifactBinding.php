<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArtifactBinding extends Model
{
    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ARCHIVED = 'archived';

    public const USABLE = 'usable';

    public const WARNING = 'warning';

    public const BLOCKED = 'blocked';

    protected $table = 'portfolio_artifact_bindings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'artifact_id' => 'integer',
            'active_revision_id' => 'integer',
            'usability_reasons_json' => 'array',
            'lock_version' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifact::class, 'artifact_id');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(ArtifactBindingRevision::class, 'active_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ArtifactBindingRevision::class, 'binding_id');
    }
}
