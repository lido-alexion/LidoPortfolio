<?php

namespace App\Models\V8;

use Illuminate\Database\Eloquent\Model;

class MlUniverseSnapshotBoundary extends Model
{
    protected $table = 'stox_ml_universe_snapshot_boundaries';
    protected $fillable = ['universe_key', 'effective_from', 'source', 'snapshot_key', 'provider_response_version', 'member_count'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'member_count' => 'integer'];
    }
}
