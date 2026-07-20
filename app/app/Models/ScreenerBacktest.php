<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenerBacktest extends Model
{
    protected $table = 'portfolio_screener_backtests';

    protected $fillable = [
        'screener_id',
        'profile_id',
        'session_token',
        'range_key',
        'status',
        'from_date',
        'to_date',
        'stats_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'screener_id' => 'integer',
            'profile_id' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
            'stats_json' => 'array',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return static::query()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('profile_id', $profile->id)
            ->first();
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(Screener::class, 'screener_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(ScreenerBacktestHit::class, 'backtest_id');
    }
}
