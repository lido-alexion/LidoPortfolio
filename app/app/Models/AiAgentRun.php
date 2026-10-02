<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAgentRun extends Model
{
    protected $table = 'stox_ai_agent_runs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['delegation_digest', 'token_id', 'scopes'];
    protected function casts(): array
    {
        return ['scopes' => 'array', 'plan' => 'array', 'preview' => 'array', 'steps' => 'array', 'trace' => 'array',
            'delegation_expires_at' => 'datetime', 'approval_expires_at' => 'datetime', 'approved_at' => 'datetime'];
    }
}
