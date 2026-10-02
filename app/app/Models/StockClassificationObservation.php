<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockClassificationObservation extends Model
{
    protected $table = 'stox_stock_classification_observations';

    protected $fillable = [
        'stock_id', 'provider', 'taxonomy_version', 'provider_sector', 'provider_industry',
        'sector', 'industry', 'source_url', 'raw_evidence_sha256', 'raw_evidence',
        'first_observed_at', 'observed_at',
    ];

    protected function casts(): array
    {
        return ['raw_evidence' => 'array', 'first_observed_at' => 'datetime', 'observed_at' => 'datetime'];
    }
}
