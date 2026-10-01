<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBudgetLimit extends Model
{
    protected $table = 'stox_ai_budget_limits';
    protected $guarded = [];
    protected function casts(): array { return ['soft_limit' => 'decimal:8', 'hard_limit' => 'decimal:8', 'spent' => 'decimal:8', 'period_started_at' => 'datetime']; }
}
