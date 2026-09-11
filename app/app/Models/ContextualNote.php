<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContextualNote extends Model
{
    protected $table = 'portfolio_contextual_notes';

    protected $fillable = [
        'user_id',
        'profile_id',
        'context_key',
        'subject_type',
        'subject_id',
        'body',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }
}
