<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dividend extends Model
{
    protected $table = 'portfolio_dividends';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount' => 'decimal:4',
            'source_evidence' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
