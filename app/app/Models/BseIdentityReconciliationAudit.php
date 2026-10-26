<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Successful StockMaster identity applications; independent of prunable SyncRun rows. */
class BseIdentityReconciliationAudit extends Model
{
    protected $table = 'portfolio_bse_identity_audits';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'plan_sha256', 'plan_json', 'result_json', 'source_fetched_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_json' => 'array', 'result_json' => 'array',
            'source_fetched_at' => 'datetime', 'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('BSE identity audits are append-only.'));
        static::deleting(fn () => throw new LogicException('BSE identity audits are append-only.'));
    }
}
