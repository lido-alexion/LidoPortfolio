<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capital repayment against an inter-strategy loan (V3 spec §28.5).
 * Not a stock transfer. Persistence only in WS4 Step 1.
 */
class CapitalLoanReturn extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_tos_loan_returns';

    protected $fillable = [
        'loan_id',
        'capital_request_id',
        'amount',
        'returned_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'loan_id' => 'integer',
            'capital_request_id' => 'integer',
            'amount' => 'decimal:4',
            'returned_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CapitalLoan::class, 'loan_id');
    }

    public function capitalRequest(): BelongsTo
    {
        return $this->belongsTo(CapitalRequest::class, 'capital_request_id');
    }
}
