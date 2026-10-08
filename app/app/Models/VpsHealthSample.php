<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpsHealthSample extends Model
{
    protected $table = 'portfolio_vps_health_samples';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'issues' => 'array',
            'metrics' => 'array',
        ];
    }
}
