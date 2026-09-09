<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRuleVersion extends Model
{
    protected $table = 'portfolio_tax_rule_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'rules' => 'array',
        ];
    }
}
