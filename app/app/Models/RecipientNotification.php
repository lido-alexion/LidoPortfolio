<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientNotification extends Model
{
    protected $table = 'portfolio_recipient_notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'latest_activity_at' => 'datetime',
            'last_successful_external_delivery_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NotificationSource::class, 'source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
