<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyScreener extends Model
{
    protected $table = 'portfolio_tos_strategy_screeners';

    protected $fillable = [
        'strategy_version_id',
        'screener_id',
        'enabled',
        'priority',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'strategy_version_id' => 'integer',
            'screener_id' => 'integer',
            'enabled' => 'boolean',
            'priority' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(TradingStrategyVersion::class, 'strategy_version_id');
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(Screener::class, 'screener_id');
    }
}
