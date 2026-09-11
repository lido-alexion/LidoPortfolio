<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WikiPage extends Model
{
    protected $table = 'portfolio_wiki_pages';

    protected $fillable = ['profile_id', 'parent_id', 'uuid', 'title', 'slug', 'markdown', 'display_order'];

    protected function casts(): array
    {
        return ['profile_id' => 'integer', 'parent_id' => 'integer', 'display_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WikiPageRevision::class, 'page_id')->orderByDesc('revision_number');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(WikiPageShare::class, 'page_id');
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeImage::class, 'portfolio_wiki_page_images', 'page_id', 'image_id');
    }
}
