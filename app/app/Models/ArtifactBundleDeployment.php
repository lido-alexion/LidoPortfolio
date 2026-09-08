<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArtifactBundleDeployment extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'portfolio_artifact_bundle_deployments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'bundle_version_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'plan_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function bundleVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'bundle_version_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArtifactBundleDeploymentItem::class, 'deployment_id');
    }
}
