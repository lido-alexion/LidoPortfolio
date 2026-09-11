<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionSafetyEvent extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_execution_safety_events';

    protected $fillable = [
        'user_id',
        'actor_user_id',
        'event',
        'provider',
        'status',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
