<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistItem extends Model
{
    protected $table = 'portfolio_watchlist_items';

    protected $fillable = [
        'profile_id',
        'stock_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'stock_id' => 'integer',
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

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
