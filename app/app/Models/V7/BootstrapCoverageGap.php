<?php

namespace App\Models\V7;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BootstrapCoverageGap extends Model
{
    protected $table = 'stox_fund_bootstrap_coverage_gaps';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_end' => 'date', 'evidence_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function stock(): BelongsTo { return $this->belongsTo(Stock::class); }

}
