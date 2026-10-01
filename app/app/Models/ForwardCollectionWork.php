<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForwardCollectionWork extends Model
{
    protected $table = 'stox_forward_collection_work';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'next_attempt_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'last_successful_at' => 'datetime',
            'owner_evidence' => 'array',
        ];
    }
}
