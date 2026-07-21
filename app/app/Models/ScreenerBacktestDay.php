<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent per-(screener, as-of date) backtest result. One row per date;
 * backtest runs reuse existing rows instead of recalculating.
 */
class ScreenerBacktestDay extends Model
{
    protected $table = 'portfolio_screener_backtest_days';

    protected $fillable = [
        'screener_id',
        'as_of_date',
        'scanned',
        'matched',
        'skipped_insufficient_data',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'screener_id' => 'integer',
            // as_of_date stays a plain Y-m-d string so date-key lookups match across drivers.
            'scanned' => 'integer',
            'matched' => 'integer',
            'skipped_insufficient_data' => 'integer',
            'errors' => 'integer',
        ];
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(Screener::class, 'screener_id');
    }
}
