<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeNote extends Model
{
    protected $table = 'portfolio_knowledge_notes';

    protected $fillable = [
        'profile_id',
        'title',
        'content_html',
        'content_json',
        'is_pinned',
        'is_favorite',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'is_pinned' => 'boolean',
            'is_favorite' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeTag::class,
            'portfolio_knowledge_note_tag',
            'note_id',
            'tag_id',
        );
    }
}
