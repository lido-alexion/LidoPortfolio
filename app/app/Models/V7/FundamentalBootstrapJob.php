<?php

namespace App\Models\V7;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundamentalBootstrapJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRY = 'retry';

    public const STATUS_COMPLETE_GOOD = 'complete_good';

    public const STATUS_COMPLETE_PARTIAL = 'complete_partial';

    public const STATUS_COMPLETE_NO_DATA = 'complete_no_data';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETE_GOOD,
        self::STATUS_COMPLETE_PARTIAL,
        self::STATUS_COMPLETE_NO_DATA,
        self::STATUS_FAILED,
    ];

    protected $table = 'stox_fundamental_bootstrap_jobs';

    protected $fillable = [
        'run_id',
        'stock_id',
        'status',
        'attempts',
        'started_at',
        'completed_at',
        'next_attempt_at',
        'last_error',
        'quarterly_status',
        'annual_status',
        'earliest_period',
        'latest_period',
        'facts_inserted',
        'facts_upgraded',
        'facts_deduped',
        'facts_rejected',
        'anomalies_count',
        'coverage_json',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'earliest_period' => 'date',
            'latest_period' => 'date',
            'facts_inserted' => 'integer',
            'facts_upgraded' => 'integer',
            'facts_deduped' => 'integer',
            'facts_rejected' => 'integer',
            'anomalies_count' => 'integer',
            'coverage_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FundamentalBootstrapRun::class, 'run_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
