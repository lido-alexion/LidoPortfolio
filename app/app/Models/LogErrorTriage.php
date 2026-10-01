<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogErrorTriage extends Model
{
    protected $table = 'stox_log_error_triages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['safe_context' => 'array', 'confidence' => 'float', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'triaged_at' => 'datetime'];
    }
}
