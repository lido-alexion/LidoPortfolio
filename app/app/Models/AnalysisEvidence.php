<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AnalysisEvidence extends Model
{
    use HasUuids;

    protected $table = 'portfolio_analysis_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'request_cutoff_at' => 'datetime',
            'assumptions' => 'array',
            'inputs_digest' => 'array',
            'result' => 'array',
        ];
    }
}
