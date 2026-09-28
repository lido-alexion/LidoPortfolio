<?php

namespace App\Models\V8;

use Illuminate\Database\Eloquent\Model;

class IntradayBackfillCheckpoint extends Model
{
    protected $table = 'stox_intraday_backfill_checkpoints';

    protected $fillable = [
        'symbol',
        'exchange',
        'window_start',
        'window_end',
        'status',
        'bars_written',
        'last_error',
        'last_attempt_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'date',
            'window_end' => 'date',
            'bars_written' => 'integer',
            'last_error' => 'array',
            'last_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
