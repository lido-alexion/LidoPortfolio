<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannelSetting extends Model
{
    protected $table = 'portfolio_notification_channel_settings';

    protected $guarded = [];

    protected $hidden = ['configuration'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'configuration' => 'encrypted:array',
            'verified_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
