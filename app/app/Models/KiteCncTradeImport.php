<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteCncTradeImport extends Model
{
    protected $table = 'kite_cnc_trade_imports';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'price' => 'decimal:4', 'executed_at' => 'datetime', 'broker_evidence' => 'array'];
    }
}
