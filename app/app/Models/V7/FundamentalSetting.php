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
        'nse_official_fallback_enabled',
        'bse_official_fallback_enabled',
        'nse_official_feed_url',
        'bse_official_feed_url',
        'ai_insights_primary_provider',
    ];

    protected function casts(): array
    {
        return [
            'quarterly_freshness_months' => 'integer',
            'annual_freshness_months' => 'integer',
            'request_delay_ms' => 'integer',
            'max_attempts' => 'integer',
            'paused' => 'boolean',
            'nse_official_fallback_enabled' => 'boolean',
            'bse_official_fallback_enabled' => 'boolean',
        ];
    }
}
