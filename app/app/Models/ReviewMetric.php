<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewMetric extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_tos_review_metrics';

    protected $fillable = [
        'report_id',
        'metric_name',
        'metric_value',
        'meta_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metric_value' => 'decimal:6',
            'meta_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ReviewReport::class, 'report_id');
    }
}
