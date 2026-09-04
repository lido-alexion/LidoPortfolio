<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalExecutionTransfer extends Model
{
    protected $table = 'portfolio_internal_execution_transfers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'provisional_unit_price' => 'decimal:4',
            'final_unit_price' => 'decimal:4',
            'finalized_at' => 'datetime',
            'audit' => 'array',
        ];
    }
}
