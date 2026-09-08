<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ReusableArtifactVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'portfolio_reusable_artifact_versions';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') === self::STATUS_PUBLISHED) {
                throw new LogicException('Published artifact versions are immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->status === self::STATUS_PUBLISHED) {
                throw new LogicException('Published artifact versions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'artifact_id' => 'integer',
            'draft_slot' => 'integer',
            'content_json' => 'array',
            'documentation_json' => 'array',
            'lock_version' => 'integer',
            'created_by_user_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifact::class, 'artifact_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ReusableArtifactDependency::class, 'source_version_id');
    }

    public function bindingRevisions(): HasMany
    {
        return $this->hasMany(ArtifactBindingRevision::class, 'artifact_version_id');
    }
}
