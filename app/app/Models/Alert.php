<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_alerts';

    protected $fillable = [
        'profile_id',
        'stock_id',
        'alert_policy_id',
        'alert_type',
        'instance_key',
        'message',
        'condition_display',
        'action_suggested',
        'context_json',
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
            'context_json' => 'array',
            'alert_policy_id' => 'integer',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return $profile->alerts()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->whereNull('expired_at');
    }

    public function isActive(): bool
    {
        return $this->expired_at === null;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AlertPolicy::class, 'alert_policy_id');
    }
}
