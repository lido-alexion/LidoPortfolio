<?php

namespace App\Models\V8;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlUniverseMembership extends Model
{
    protected $table = 'stox_ml_universe_memberships';

    protected $fillable = [
        'stock_id',
        'universe_key',
        'effective_from',
        'effective_to',
        'sector_snapshot',
        'source',
        'snapshot_key',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
