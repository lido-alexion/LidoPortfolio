<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidate extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_tos_candidates';

    protected $fillable = [
        'discovery_run_id',
        'security_id',
        'source',
        'evidence',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id');
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'security_id');
    }

    public function evaluationResult(): HasOne
    {
        return $this->hasOne(EvaluationResult::class, 'candidate_id');
    }
}
