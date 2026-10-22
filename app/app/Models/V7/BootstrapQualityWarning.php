<?php

namespace App\Models\V7;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BootstrapQualityWarning extends Model
{
    protected $table = 'stox_fund_bootstrap_quality_warnings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime', 'details' => 'array'];
    }

    public function stock(): BelongsTo { return $this->belongsTo(Stock::class); }
}
