<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;

class BootstrapScheduleOccurrence extends Model
{
    protected $table = 'stox_bootstrap_schedule_occurrences';
    protected $guarded = ['id'];
    protected function casts(): array { return ['configuration_snapshot' => 'array', 'scheduled_at_utc' => 'immutable_datetime', 'scheduled_at_local' => 'immutable_datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime']; }
}
