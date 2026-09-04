<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionBatch extends Model
{
    protected $table = 'portfolio_execution_batches';

    protected $fillable = [
        'user_id', 'provider', 'cycle_key', 'status', 'started_at', 'completed_at', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
