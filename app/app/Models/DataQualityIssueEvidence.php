<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataQualityIssueEvidence extends Model
{
    protected $table = 'portfolio_data_quality_issue_evidence';

    protected $fillable = [
        'issue_id',
        'evidence_key',
        'evidence_label',
        'evidence_value',
        'evidence_payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_id' => 'integer',
            'evidence_payload' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(DataQualityIssue::class, 'issue_id');
    }
}
