<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionImportBatch extends Model
{
    public const STATUS_COMMITTED = 'committed';

    protected $table = 'portfolio_transaction_import_batches';

    protected $fillable = [
        'batch_key',
        'profile_id',
        'user_id',
        'status',
        'row_count',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'user_id' => 'integer',
            'row_count' => 'integer',
            'committed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionImportBatchItem::class, 'batch_id');
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }
}
