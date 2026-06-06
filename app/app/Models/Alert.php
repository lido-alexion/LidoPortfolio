<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_alerts';

    protected $fillable = [
        'user_id',
        'stock_id',
        'alert_type',
        'message',
        'is_sent',
        'created_at',
        'expired_at',
        'expiration_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_sent' => 'boolean',
            'created_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->whereNull('expired_at');
    }

    public function isActive(): bool
    {
        return $this->expired_at === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
