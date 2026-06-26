<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertPolicy extends Model
{
    protected $table = 'portfolio_alert_policies';

    protected $fillable = [
        'profile_id',
        'name',
        'stock_universe',
        'alert_definition',
        'condition_column',
        'condition_operator',
        'compare_type',
        'compare_column',
        'compare_formula',
        'compare_constant',
        'message_template',
        'action_type',
        'action_custom',
        'context_columns',
        'is_enabled',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'compare_constant' => 'decimal:6',
            'context_columns' => 'array',
            'is_enabled' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return static::query()
            ->where('profile_id', $profile->id)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'alert_policy_id');
    }
}
