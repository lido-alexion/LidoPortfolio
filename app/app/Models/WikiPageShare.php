<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiPageShare extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_wiki_page_shares';

    protected $fillable = ['page_id', 'token_hash', 'token_encrypted', 'created_at', 'revoked_at'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['page_id' => 'integer', 'created_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }
}
