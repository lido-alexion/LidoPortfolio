<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EvaluationResult extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_tos_evaluation_results';

    protected $fillable = [
        'evaluation_run_id',
        'candidate_id',
        'score',
        'confidence',
        'rank',
        'evidence',
        'passed_rules',
        'failed_rules',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:6',
            'confidence' => 'decimal:4',
            'evidence' => 'array',
            'passed_rules' => 'array',
            'failed_rules' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function evaluationRun(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'evaluation_run_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    public function recommendation(): HasOne
    {
        return $this->hasOne(TradingRecommendation::class, 'evaluation_result_id');
    }
}
