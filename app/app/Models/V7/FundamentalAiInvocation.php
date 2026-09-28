<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;

class FundamentalAiInvocation extends Model
{
    public $timestamps = false;

    protected $table = 'stox_fundamental_ai_invocations';

    protected $fillable = [
        'user_id',
        'stock_id',
        'feature_key',
        'provider',
        'provider_role',
        'status',
        'failover_from',
        'latency_ms',
        'prompt_version',
        'model',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'error_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'failover_from' => 'array',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
