<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReusableArtifact extends Model
{
    protected $table = 'portfolio_reusable_artifacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'provenance_json' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReusableArtifactVersion::class, 'artifact_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(ArtifactBinding::class, 'artifact_id');
    }
}
