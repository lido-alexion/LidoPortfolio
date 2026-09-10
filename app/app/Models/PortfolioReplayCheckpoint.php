<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PortfolioReplayCheckpoint extends Model
{
    protected $table = 'portfolio_replay_checkpoints';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Replay checkpoints are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'effective_session_date' => 'date', 'processed_at' => 'datetime',
            'state_before' => 'array', 'state_after' => 'array',
            'market_evidence' => 'array', 'limitations' => 'array',
        ];
    }
}
