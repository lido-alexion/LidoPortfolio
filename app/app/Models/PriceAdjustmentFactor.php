<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAdjustmentFactor extends Model
{
    public const REPAIR_STATUS_PENDING = 'pending';

    public const REPAIR_STATUS_COMPLETED = 'completed';

    protected $table = 'portfolio_price_adjustment_factors';

    protected $fillable = [
        'stock_id',
        'issue_id',
        'factor_type',
        'action_type',
        'effective_ex_date',
        'applied_ratio',
        'price_divisor',
        'volume_multiplier',
        'is_active',
        'applied_at',
        'reversed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'stock_id' => 'integer',
            'issue_id' => 'integer',
            'effective_ex_date' => 'date',
            'applied_ratio' => 'decimal:6',
            'price_divisor' => 'decimal:6',
            'volume_multiplier' => 'decimal:6',
            'is_active' => 'boolean',
            'applied_at' => 'datetime',
            'reversed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(DataQualityIssue::class, 'issue_id');
    }

    public function scopePendingOhlcvRepair($query)
    {
        return $query
            ->where('is_active', true)
            ->where('metadata->ohlcv_repair_status', self::REPAIR_STATUS_PENDING);
    }

    /**
     * Active F042 factors that own OHLCV repair for a corporate-action event
     * (pending or already completed). Used so F020 / F043 CA recovery do not
     * restate the same stock+ex-date+action twice.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActiveOhlcvRepairForEvent($query, int $stockId, string $exDate, ?string $corporateActionType = null)
    {
        $query
            ->where('stock_id', $stockId)
            ->whereDate('effective_ex_date', $exDate)
            ->where('is_active', true)
            ->where(function ($statusQuery) {
                $statusQuery
                    ->where('metadata->ohlcv_repair_status', self::REPAIR_STATUS_PENDING)
                    ->orWhere('metadata->ohlcv_repair_status', self::REPAIR_STATUS_COMPLETED);
            });

        if ($corporateActionType !== null && $corporateActionType !== '') {
            $query->whereIn('action_type', self::factorActionTypesMatchingCorporateAction($corporateActionType));
        }

        return $query;
    }

    /**
     * Map an F020 corporate-action type to F042 factor action_type values that
     * represent the same OHLCV event.
     *
     * @return list<string>
     */
    public static function factorActionTypesMatchingCorporateAction(string $corporateActionType): array
    {
        return match (strtolower($corporateActionType)) {
            'split' => ['split', 'face_value_split'],
            'bonus' => ['bonus'],
            default => [strtolower($corporateActionType)],
        };
    }

    public static function findActiveOhlcvRepairForEvent(int $stockId, string $exDate, ?string $corporateActionType = null): ?self
    {
        return static::query()
            ->activeOhlcvRepairForEvent($stockId, $exDate, $corporateActionType)
            ->orderBy('id')
            ->first();
    }
}
