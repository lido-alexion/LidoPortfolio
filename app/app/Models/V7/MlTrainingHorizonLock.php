<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;

class MlTrainingHorizonLock extends Model
{
    protected $table = 'stox_ml_training_horizon_locks';

    protected $primaryKey = 'horizon';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['horizon'];
}
