<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;

class MlLifecycleSchedule extends Model
{
    protected $table = 'stox_ml_lifecycle_schedules';
    protected $primaryKey = 'horizon';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['horizon', 'enabled', 'schedule', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
