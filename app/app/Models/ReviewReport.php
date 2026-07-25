<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewReport extends Model
{
    protected $table = 'portfolio_tos_review_reports';

    protected $fillable = [
        'profile_id',
        'period_start',
        'period_end',
        'status',
        'generated_at',
        'summary_json',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
            'summary_json' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ReviewMetric::class, 'report_id');
    }
}
