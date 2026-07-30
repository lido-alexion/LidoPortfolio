<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DataQualityIssue extends Model
{
    protected $table = 'portfolio_data_quality_issues';

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const TYPE_CORPORATE_ACTION = 'corporate_action';

    protected $fillable = [
        'stock_id',
        'symbol',
        'issue_type',
        'issue_status',
        'detection_method',
        'detection_source',
        'suggested_ratio',
        'confidence',
        'corporate_action_type',
        'ex_date',
        'record_date',
        'previous_close',
        'current_open',
        'gap_percent',
        'gap_ratio',
        'volume_change_percent',
        'exchange_match',
        'detection_payload',
        'raw_payload',
        'detected_at',
        'resolved_at',
        'auto_resolved',
        'applied_ratio',
        'latest_suggested_ratio',
        'latest_resolution_id',
    ];

    protected function casts(): array
    {
        return [
            'stock_id' => 'integer',
            'suggested_ratio' => 'decimal:6',
            'confidence' => 'decimal:4',
            'ex_date' => 'date',
            'record_date' => 'date',
            'previous_close' => 'decimal:4',
            'current_open' => 'decimal:4',
            'gap_percent' => 'decimal:4',
            'gap_ratio' => 'decimal:6',
            'volume_change_percent' => 'decimal:4',
            'exchange_match' => 'boolean',
            'detection_payload' => 'array',
            'raw_payload' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'auto_resolved' => 'boolean',
            'applied_ratio' => 'decimal:6',
            'latest_suggested_ratio' => 'decimal:6',
            'latest_resolution_id' => 'integer',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(DataQualityIssueResolution::class, 'issue_id');
    }

    public function latestResolution(): HasOne
    {
        return $this->hasOne(DataQualityIssueResolution::class, 'issue_id')->latestOfMany('resolved_at');
    }

    public function linkedResolution(): BelongsTo
    {
        return $this->belongsTo(DataQualityIssueResolution::class, 'latest_resolution_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(DataQualityIssueEvidence::class, 'issue_id');
    }
}
