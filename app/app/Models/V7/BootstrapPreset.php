<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BootstrapPreset extends Model
{
    protected $table = 'stox_bootstrap_presets';
    protected $guarded = ['id'];
    protected function casts(): array { return ['configuration' => 'array', 'revision' => 'integer']; }
    public function schedules(): HasMany { return $this->hasMany(BootstrapSchedule::class, 'preset_id'); }
}
