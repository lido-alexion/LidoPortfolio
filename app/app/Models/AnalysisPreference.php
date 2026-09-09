<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisPreference extends Model
{
    protected $table = 'portfolio_analysis_preferences';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'comparison_benchmark_ids' => 'array',
            'include_in_account_performance' => 'boolean',
            'include_in_account_tax' => 'boolean',
            'risk_free_rate' => 'decimal:8',
            'annualization_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function primaryBenchmark(): BelongsTo
    {
        return $this->belongsTo(Benchmark::class, 'primary_benchmark_id');
    }
}
