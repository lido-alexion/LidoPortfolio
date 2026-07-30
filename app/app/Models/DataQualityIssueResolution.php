<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataQualityIssueResolution extends Model
{
    protected $table = 'portfolio_data_quality_issue_resolutions';

    public const TYPE_ACCEPTED = 'accepted';
    public const TYPE_REJECTED = 'rejected';
    public const TYPE_MODIFIED_ACCEPTED = 'modified_accepted';
    public const TYPE_AUTO_ACCEPTED = 'auto_accepted';
    public const TYPE_MIGRATED = 'migrated';
    public const TYPE_REVERSED = 'reversed';

    protected $fillable = [
        'issue_id',
        'resolution_type',
        'resolution_status',
        'applied_ratio',
        'suggested_ratio_snapshot',
        'is_reversal',
        'supersedes_resolution_id',
        'resolved_by',
        'notes',
        'metadata',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_id' => 'integer',
            'applied_ratio' => 'decimal:6',
            'suggested_ratio_snapshot' => 'decimal:6',
            'is_reversal' => 'boolean',
            'supersedes_resolution_id' => 'integer',
            'resolved_by' => 'integer',
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(DataQualityIssue::class, 'issue_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
