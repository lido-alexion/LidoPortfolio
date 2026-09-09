<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxLoss extends Model
{
    protected $table = 'portfolio_tax_losses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
