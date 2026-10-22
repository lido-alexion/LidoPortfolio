<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BootstrapSchedule extends Model
{
    protected $table = 'stox_bootstrap_schedules';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['configuration_snapshot' => 'array', 'starts_at_local' => 'datetime', 'ends_on_local' => 'date', 'next_occurrence_at' => 'datetime', 'enabled' => 'boolean', 'version' => 'integer', 'generation' => 'integer', 'interval' => 'integer', 'max_occurrences' => 'integer', 'occurrences_created' => 'integer'];
    }
    public function occurrences(): HasMany { return $this->hasMany(BootstrapScheduleOccurrence::class, 'schedule_id'); }
}
