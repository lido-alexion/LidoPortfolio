<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IgnoredPriceGap extends Model
{
    protected $table = 'portfolio_ignored_price_gaps';

    protected $fillable = [
        'stock_id',
        'gap_from',
        'gap_to',
        'ignored_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'gap_from' => 'date',
            'gap_to' => 'date',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_user_id');
    }
}
