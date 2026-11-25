<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteSessionEvidence extends Model
{
    protected $table = 'kite_session_evidence';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'session_date' => 'date', 'evidence' => 'array', 'source_timestamps' => 'array',
            'blocking_reasons' => 'array', 'captured_at' => 'datetime',
        ];
    }
}
