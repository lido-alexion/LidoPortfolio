<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingStrategy extends Model
{
    protected $table = 'portfolio_tos_strategies';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'profile_id',
        'name',
        'slug',
        'definition_hash',
        'description',
        'intent',
        'summary',
        'tags_json',
        'status',
        'is_factory',
        'factory_key',
        'duplicated_from_id',
        'active_version_id',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'active_version_id' => 'integer',
            'duplicated_from_id' => 'integer',
            'is_factory' => 'boolean',
            'tags_json' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TradingStrategyVersion::class, 'strategy_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(TradingStrategyVersion::class, 'active_version_id');
    }

    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicated_from_id');
    }
}
