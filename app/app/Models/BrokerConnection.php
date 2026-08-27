<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerConnection extends Model
{
    public const PROVIDER_KITE = 'kite';

    protected $table = 'portfolio_broker_connections';

    protected $hidden = [
        'access_token',
    ];

    protected $fillable = [
        'user_id',
        'provider',
        'broker_user_id',
        'connected_at',
        'expires_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        if (! is_string($this->access_token) || $this->access_token === '') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
