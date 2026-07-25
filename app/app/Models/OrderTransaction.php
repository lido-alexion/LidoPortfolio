<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTransaction extends Model
{
    protected $table = 'portfolio_tos_order_transactions';

    protected $fillable = [
        'order_id',
        'transaction_id',
        'execution_price',
        'quantity',
        'charges',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'execution_price' => 'decimal:4',
            'quantity' => 'decimal:4',
            'charges' => 'decimal:4',
            'executed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TradingOrder::class, 'order_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
