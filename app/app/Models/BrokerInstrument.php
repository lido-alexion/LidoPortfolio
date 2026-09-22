<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerInstrument extends Model
{
    protected $table = 'stox_broker_instruments';

    protected $fillable = [
        'provider', 'stock_id', 'exchange', 'trading_symbol', 'instrument_token',
        'exchange_token', 'series', 'broker_name', 'raw_metadata', 'is_active',
        'last_seen_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_metadata' => 'array',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
