<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInsightCache extends Model
{
    protected $table = 'stox_ai_insight_cache';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['response' => 'array', 'data_as_of' => 'array', 'generated_at' => 'datetime', 'refresh_failed_at' => 'datetime'];
    }
}
