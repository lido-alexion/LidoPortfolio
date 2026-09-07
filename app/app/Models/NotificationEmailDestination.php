<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationEmailDestination extends Model
{
    protected $table = 'portfolio_notification_email_destinations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_account_email' => 'boolean',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
