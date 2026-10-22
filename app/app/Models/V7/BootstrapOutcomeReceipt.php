<?php

namespace App\Models\V7;

use Illuminate\Database\Eloquent\Model;

class BootstrapOutcomeReceipt extends Model
{
    protected $table = 'stox_bootstrap_outcome_receipts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
