<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteCncTradeSyncRun extends Model
{
    protected $table = 'kite_cnc_trade_sync_runs';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trading_date' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
