<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistPatternScan extends Model
{
    protected $table = 'portfolio_watchlist_pattern_scans';

    protected $fillable = [
        'profile_id',
        'watchlist_id',
        'stock_id',
        'matches',
        'price_as_of',
        'expires_at',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'watchlist_id' => 'integer',
            'stock_id' => 'integer',
            'matches' => 'array',
            'price_as_of' => 'date',
            'expires_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class, 'watchlist_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
