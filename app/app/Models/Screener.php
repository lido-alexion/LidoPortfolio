<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screener extends Model
{
    protected $table = 'portfolio_screeners';

    protected $fillable = [
        'profile_id',
        'name',
        'slug',
        'artifact_version',
        'definition_hash',
        'description',
        'intent',
        'summary',
        'tags_json',
        'artifact_status',
        'scope',
        'watchlist_id',
        'index_symbol',
        'definition_json',
        'schedule_enabled',
        'schedule_time',
        'schedule_days',
        'telegram_enabled',
        'is_enabled',
        'is_shared',
        'is_factory',
        'factory_key',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'watchlist_id' => 'integer',
            'artifact_version' => 'integer',
            'definition_json' => 'array',
            'tags_json' => 'array',
            'schedule_enabled' => 'boolean',
            'schedule_days' => 'array',
            'telegram_enabled' => 'boolean',
            'is_enabled' => 'boolean',
            'is_shared' => 'boolean',
            'is_factory' => 'boolean',
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

    /**
     * Shared screeners from other profiles owned by the same user (F060).
     */
    public function scopeSharedVisibleTo(Builder $query, PortfolioProfile $profile): Builder
    {
        return $query
            ->where('is_shared', true)
            ->where('profile_id', '!=', $profile->id)
            ->whereHas('profile', function (Builder $q) use ($profile) {
                $q->where('user_id', $profile->user_id);
            });
    }

    /**
     * Own screeners, or same-user shared screeners from other profiles (F060).
     */
    public function scopeOwnedOrSameUserShared(Builder $query, PortfolioProfile $profile): Builder
    {
        return $query->where(function (Builder $q) use ($profile) {
            $q->where('profile_id', $profile->id)
                ->orWhere(function (Builder $inner) use ($profile) {
                    $inner->where('is_shared', true)
                        ->where('profile_id', '!=', $profile->id)
                        ->whereHas('profile', function (Builder $pq) use ($profile) {
                            $pq->where('user_id', $profile->user_id);
                        });
                });
        });
    }

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class, 'watchlist_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScreenerRun::class, 'screener_id');
    }

    public function backtests(): HasMany
    {
        return $this->hasMany(ScreenerBacktest::class, 'screener_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScreenerVersion::class, 'screener_id');
    }
}
