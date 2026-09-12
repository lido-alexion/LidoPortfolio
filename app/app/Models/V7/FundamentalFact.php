<?php

namespace App\Models\V7;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundamentalFact extends Model
{
    protected $table = 'stox_fundamental_facts';

    protected $fillable = [
        'stock_id',
        'provider',
        'statement_type',
        'cadence',
        'statement_basis',
        'fact_key',
        'period_start',
        'period_end',
        'reported_period',
        'value',
        'currency',
        'availability_date',
        'first_fetched_at',
        'revision_hash',
        'revision_number',
        'is_current',
        'source_meta',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'reported_period' => 'date',
            'value' => 'decimal:6',
            'availability_date' => 'date',
            'first_fetched_at' => 'datetime',
            'revision_number' => 'integer',
            'is_current' => 'boolean',
            'source_meta' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }
}
