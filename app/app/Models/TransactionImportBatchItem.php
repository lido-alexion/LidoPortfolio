<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionImportBatchItem extends Model
{
    protected $table = 'portfolio_transaction_import_batch_items';

    protected $fillable = [
        'batch_id',
        'row_key',
        'sort_order',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'sort_order' => 'integer',
            'transaction_id' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TransactionImportBatch::class, 'batch_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
