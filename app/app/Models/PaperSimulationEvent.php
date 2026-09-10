<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperSimulationEvent extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_paper_simulation_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'user_id' => 'integer',
            'effective_session_date' => 'date',
            'evidence' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
