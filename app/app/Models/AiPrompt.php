<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPrompt extends Model
{
    protected $table = 'stox_ai_prompts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input_schema' => 'array', 'output_schema' => 'array', 'active' => 'boolean'];
    }
}
