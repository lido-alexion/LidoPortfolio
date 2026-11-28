<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpsHealthDiskHourlyRollup extends Model
{
    protected $table = 'portfolio_vps_health_disk_hourly_rollups';
    protected $primaryKey = 'hour_start';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['hour_start' => 'datetime', 'sampled_at' => 'datetime', 'metrics' => 'array'];
    }
}
