<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenerRun extends Model
{
    protected $table = 'portfolio_screener_runs';

    protected $fillable = [
        'screener_id',
        'triggered_by',
        'status',
        'started_at',
        'finished_at',
        'stats_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'screener_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
            ->whereHas('screener', fn ($q) => $q->where('profile_id', $profile->id))
            ->first();
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(Screener::class, 'screener_id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(ScreenerRunHit::class, 'run_id');
    }
}
