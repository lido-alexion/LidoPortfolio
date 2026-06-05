<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'portfolio_transactions';

    protected $fillable = [
        'user_id',
        'stock_id',
        'type',
        'quantity',
        'price',
        'brokerage',
        'transaction_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'price' => 'decimal:4',
            'brokerage' => 'decimal:4',
            'transaction_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
