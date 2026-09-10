<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Benchmark extends Model
{
    protected $table = 'portfolio_benchmarks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provenance' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
