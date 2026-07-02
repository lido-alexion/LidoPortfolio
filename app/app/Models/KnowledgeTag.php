<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeTag extends Model
{
    protected $table = 'portfolio_knowledge_tags';

    protected $fillable = [
        'profile_id',
        'name',
        'color',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeNote::class,
            'portfolio_knowledge_note_tag',
            'tag_id',
            'note_id',
        );
    }
}
