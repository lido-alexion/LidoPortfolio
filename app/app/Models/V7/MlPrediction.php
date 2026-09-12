<?php

namespace App\Models\V7;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlPrediction extends Model
{
    protected $table = 'stox_ml_predictions';

    protected $fillable = [
        'stock_id',
        'model_version_id',
        'horizon',
        'as_of',
        'score',
        'confidence',
        'benchmark_symbol',
        'shadow',
        'explanations',
        'feature_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'score' => 'decimal:4',
            'confidence' => 'decimal:4',
            'shadow' => 'boolean',
            'explanations' => 'array',
            'feature_snapshot' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'model_version_id');
    }
}
