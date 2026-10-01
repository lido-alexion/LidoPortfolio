<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorporateActionFeedCheckpoint extends Model
{
    protected $table = 'stox_corporate_action_feed_checkpoints';
    protected $guarded = [];
    protected function casts(): array { return ['last_attempted_at' => 'datetime', 'last_successful_at' => 'datetime', 'window_from' => 'date', 'window_to' => 'date']; }
}
