<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationDelivery extends Model
{
    protected $table = 'portfolio_notification_deliveries';

    protected $guarded = [];

    protected $hidden = ['destination'];

    protected function casts(): array
    {
        return [
            'destination' => 'encrypted',
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
            'suppressed_at' => 'datetime',
        ];
    }

    public function recipientNotification(): BelongsTo
    {
        return $this->belongsTo(RecipientNotification::class);
    }

    public function channelSetting(): BelongsTo
    {
        return $this->belongsTo(NotificationChannelSetting::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class, 'delivery_id');
    }
}
