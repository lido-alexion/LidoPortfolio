<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningTaxLot extends Model
{
    protected $table = 'portfolio_opening_tax_lots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'quantity' => 'decimal:4',
            'cost_basis' => 'decimal:4',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
