<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenerBacktestHit extends Model
{
    protected $table = 'portfolio_screener_backtest_hits';

    protected $fillable = [
        'screener_id',
        'screener_version_id',
        'as_of_date',
        'stock_id',
        'symbol',
        'exchange',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'screener_id' => 'integer',
            'screener_version_id' => 'integer',
            'stock_id' => 'integer',
            // as_of_date stays a plain Y-m-d string so date-key lookups match across drivers.
        ];
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(Screener::class, 'screener_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }
}
