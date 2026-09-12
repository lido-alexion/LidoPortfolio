<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;

class FundamentalSetting extends Model
{
    protected $table = 'stox_fundamental_settings';

    protected $fillable = [
        'quarterly_freshness_months',
        'annual_freshness_months',
        'request_delay_ms',
        'max_attempts',
        'provider',
        'paused',
    ];

    protected function casts(): array
    {
        return [
            'quarterly_freshness_months' => 'integer',
            'annual_freshness_months' => 'integer',
            'request_delay_ms' => 'integer',
            'max_attempts' => 'integer',
            'paused' => 'boolean',
        ];
    }
}
