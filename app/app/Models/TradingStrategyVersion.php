<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingStrategyVersion extends Model
{
    protected $table = 'portfolio_tos_strategy_versions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'strategy_id',
        'version',
        'version_label',
        'config_json',
        'status',
        'change_notes',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'strategy_id' => 'integer',
            'version' => 'integer',
            'config_json' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'strategy_id');
    }
}
