<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAccount extends Model
{
    protected $table = 'portfolio_cash_accounts';

    protected $fillable = [
        'profile_id',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'balance' => 'decimal:4',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CashLedgerEntry::class, 'profile_id', 'profile_id');
    }
}
