<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Watchlist extends Model
{
    protected $table = 'portfolio_watchlists';

    protected $fillable = [
        'profile_id',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'sort_order' => 'integer',
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

    public function items(): HasMany
    {
        return $this->hasMany(WatchlistItem::class, 'watchlist_id');
    }

    public function patternScans(): HasMany
    {
        return $this->hasMany(WatchlistPatternScan::class, 'watchlist_id');
    }
}
