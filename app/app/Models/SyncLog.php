<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $table = 'portfolio_sync_logs';

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'job_name',
        'level',
        'message',
        'context',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'run_id');
    }
}
