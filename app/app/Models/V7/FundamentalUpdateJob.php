<?php

namespace App\Models\V7;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundamentalUpdateJob extends Model
{
    protected $table = 'stox_fundamental_update_jobs';

    protected $fillable = [
        'run_id',
        'stock_id',
        'cadence',
        'status',
        'priority_tier',
        'attempts',
        'last_attempted_at',
        'next_attempt_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'priority_tier' => 'integer',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FundamentalUpdateRun::class, 'run_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }
}
