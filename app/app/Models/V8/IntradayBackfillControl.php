<?php

namespace App\Models\V8;

use Illuminate\Database\Eloquent\Model;

class IntradayBackfillControl extends Model
{
    protected $table = 'stox_intraday_backfill_controls';

    protected $fillable = ['control_key', 'paused', 'updated_by'];

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'updated_by' => 'integer'];
    }
}
