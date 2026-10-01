<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderPath extends Model
{
    protected $table = 'stox_ai_provider_paths';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'array', 'enabled' => 'boolean'];
    }
}
