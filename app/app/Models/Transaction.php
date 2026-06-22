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
        'fees',
        'transaction_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'stock_id' => 'integer',
            'quantity' => 'decimal:4',
            'price' => 'decimal:4',
            'fees' => 'decimal:4',
            'transaction_date' => 'date',
        ];
    }

    /**
     * Only resolve transactions owned by the authenticated user (API update/delete/show).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return $user->transactions()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
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
