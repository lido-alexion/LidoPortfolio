<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDigestMembership extends Model
{
    protected $table = 'portfolio_notification_digest_memberships';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['digest_date' => 'date', 'delivered_at' => 'datetime'];
    }

    public function recipientNotification(): BelongsTo
    {
        return $this->belongsTo(RecipientNotification::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class);
    }
}
