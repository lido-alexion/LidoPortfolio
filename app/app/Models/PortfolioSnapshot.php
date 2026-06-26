<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioSnapshot extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_portfolio_snapshots';

    protected $fillable = [
        'profile_id',
        'snapshot_date',
        'portfolio_value',
        'invested_value',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'portfolio_value' => 'decimal:4',
            'invested_value' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }
}
