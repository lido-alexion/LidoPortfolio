<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCapability extends Model
{
    protected $table = 'stox_ai_capabilities';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['path_order' => 'array', 'output_schema' => 'array', 'enabled' => 'boolean'];
    }
}
