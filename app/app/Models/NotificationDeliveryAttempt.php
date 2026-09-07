<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDeliveryAttempt extends Model
{
    protected $table = 'portfolio_notification_delivery_attempts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class);
    }
}
