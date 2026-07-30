<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingArtifactDraft extends Model
{
    protected $table = 'portfolio_trading_artifact_drafts';

    protected $fillable = [
        'profile_id',
        'artifact_type',
        'artifact_uuid',
        'slug',
        'name',
        'artifact_version',
        'status',
        'origin',
        'definition_hash',
        'envelope_json',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'artifact_version' => 'integer',
            'envelope_json' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }
}
