<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateAction extends Model
{
    protected $table = 'portfolio_corporate_actions';

    protected $fillable = [
        'profile_id',
        'stock_id',
        'action_type',
        'ratio_from',
        'ratio_to',
        'ex_date',
        'notes',
        'applied_at',
        'created_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'stock_id' => 'integer',
            'ratio_from' => 'integer',
            'ratio_to' => 'integer',
            'ex_date' => 'date',
            'applied_at' => 'datetime',
            'created_by' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return $profile->corporateActions()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'corporate_action_id');
    }
}
