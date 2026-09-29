<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'token', 'dataset', 'format', 'path', 'status', 'expires_at', 'cancelled_at', 'metadata'])]
class ExportArtifact extends Model
{
    protected $table = 'portfolio_export_artifacts';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'cancelled_at' => 'datetime', 'metadata' => 'array'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
