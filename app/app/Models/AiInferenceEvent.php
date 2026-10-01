<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInferenceEvent extends Model
{
    protected $table = 'stox_ai_inference_events';
    protected $guarded = [];
    protected function casts(): array { return ['routing_trace' => 'array', 'provenance' => 'array', 'usage' => 'array']; }
}
