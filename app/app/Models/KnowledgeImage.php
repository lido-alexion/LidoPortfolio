<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeImage extends Model
{
    protected $table = 'portfolio_knowledge_images';

    protected $fillable = [
        'profile_id',
        'uuid',
        'original_name',
        'mime_type',
        'display_filename',
        'full_filename',
        'display_width',
        'display_height',
        'full_width',
        'full_height',
        'display_bytes',
        'full_bytes',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'display_width' => 'integer',
            'display_height' => 'integer',
            'full_width' => 'integer',
            'full_height' => 'integer',
            'display_bytes' => 'integer',
            'full_bytes' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }
}
