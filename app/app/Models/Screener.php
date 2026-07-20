<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screener extends Model
{
    protected $table = 'portfolio_screeners';

    protected $fillable = [
        'profile_id',
        'name',
        'description',
        'scope',
        'watchlist_id',
        'definition_json',
        'schedule_enabled',
        'schedule_time',
        'schedule_days',
        'telegram_enabled',
        'is_enabled',
        'is_shared',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'watchlist_id' => 'integer',
            'definition_json' => 'array',
            'schedule_enabled' => 'boolean',
            'schedule_days' => 'array',
            'telegram_enabled' => 'boolean',
            'is_enabled' => 'boolean',
            'is_shared' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return static::query()
            ->where('profile_id', $profile->id)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class, 'watchlist_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScreenerRun::class, 'screener_id');
    }
}
