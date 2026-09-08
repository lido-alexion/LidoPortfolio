<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactBundleDeploymentItem extends Model
{
    protected $table = 'portfolio_artifact_bundle_deployment_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deployment_id' => 'integer',
            'member_version_id' => 'integer',
            'binding_id' => 'integer',
            'previous_revision_id' => 'integer',
            'resulting_revision_id' => 'integer',
            'settings_json' => 'array',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ArtifactBundleDeployment::class, 'deployment_id');
    }

    public function memberVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'member_version_id');
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(ArtifactBinding::class, 'binding_id');
    }
}
