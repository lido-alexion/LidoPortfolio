<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperExecutionEvent extends Model
{
    protected $table = 'portfolio_paper_execution_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_session_date' => 'date', 'processed_at' => 'datetime',
            'requested_quantity' => 'decimal:4', 'executed_quantity' => 'decimal:4',
            'execution_price' => 'decimal:4', 'evidence' => 'array',
        ];
    }
}
