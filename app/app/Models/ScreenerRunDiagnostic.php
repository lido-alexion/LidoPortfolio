<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenerRunDiagnostic extends Model
{
    protected $table = 'portfolio_screener_run_diagnostics';

    protected $fillable = [
        'run_id',
        'stock_id',
        'symbol',
        'exchange',
        'name',
        'outcome',
        'reason',
        'metrics_json',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'stock_id' => 'integer',
            'metrics_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScreenerRun::class, 'run_id');
    }
}
