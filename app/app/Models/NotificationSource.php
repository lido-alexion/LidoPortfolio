<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationSource extends Model
{
    protected $table = 'portfolio_notification_sources';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'primary_action' => 'array',
            'external_info_delivery' => 'boolean',
            'first_detected_at' => 'datetime',
            'latest_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(RecipientNotification::class, 'source_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(NotificationOccurrence::class, 'source_id');
    }
}
