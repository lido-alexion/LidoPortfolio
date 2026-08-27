<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable identity for a successful market-dataset sync (V4-FEAT-023).
 * Rows are insert-only; later syncs create new versions.
 */
class DatasetVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'portfolio_tos_dataset_versions';

    protected $fillable = [
        'version_key',
        'synced_at',
        'latest_price_date',
        'price_bars',
        'securities_active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'latest_price_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Dataset versions are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Dataset versions are immutable.');
        });
    }
}
