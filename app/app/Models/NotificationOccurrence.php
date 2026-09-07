<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationOccurrence extends Model
{
    protected $table = 'portfolio_notification_occurrences';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NotificationSource::class, 'source_id');
    }
}
