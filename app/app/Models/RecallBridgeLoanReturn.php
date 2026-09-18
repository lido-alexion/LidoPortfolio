<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable repayment evidence for a Recall Bridge Loan. */
class RecallBridgeLoanReturn extends Model
{
    protected $table = 'portfolio_tos_recall_bridge_loan_returns';
    public $timestamps = false;

    protected $fillable = ['bridge_loan_id', 'amount', 'returned_at', 'created_at'];

    protected function casts(): array
    {
        return ['bridge_loan_id' => 'integer', 'amount' => 'decimal:4', 'returned_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
