<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WikiPageRevision extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_wiki_page_revisions';

    protected $fillable = ['page_id', 'user_id', 'revision_number', 'change_type', 'title', 'slug', 'parent_id', 'display_order', 'markdown', 'created_at'];

    protected function casts(): array
    {
        return ['page_id' => 'integer', 'user_id' => 'integer', 'parent_id' => 'integer', 'display_order' => 'integer', 'revision_number' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Wiki revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Wiki revisions are immutable.'));
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }
}
