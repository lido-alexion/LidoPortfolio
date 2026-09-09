<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
