<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'portfolio_transactions';

    protected $fillable = [
        'profile_id',
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
            'profile_id' => 'integer',
            'stock_id' => 'integer',
            'quantity' => 'decimal:4',
            'price' => 'decimal:4',
            'fees' => 'decimal:4',
            'transaction_date' => 'date',
        ];
    }

    /**
     * Only resolve transactions owned by the active portfolio (API update/delete/show).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return $profile->transactions()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
