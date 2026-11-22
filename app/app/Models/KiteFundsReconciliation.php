<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteFundsReconciliation extends Model
{
    protected $table = 'portfolio_kite_funds_reconciliations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['trading_date' => 'date', 'snapshot_net_flow' => 'decimal:4',
            'manual_net_flow' => 'decimal:4', 'evaluated_at' => 'datetime'];
    }
}
