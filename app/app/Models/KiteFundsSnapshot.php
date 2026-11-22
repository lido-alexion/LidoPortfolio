<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteFundsSnapshot extends Model
{
    protected $table = 'portfolio_kite_funds_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trading_date' => 'date', 'captured_at' => 'datetime',
            'available_balance' => 'decimal:4', 'opening_balance' => 'decimal:4',
            'intraday_payin' => 'decimal:4', 'utilised_payout' => 'decimal:4',
            'payout_semantics_verified' => 'boolean', 'provenance' => 'array',
        ];
    }
}
