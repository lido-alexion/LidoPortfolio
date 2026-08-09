<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;

class DataQualityAdjustmentFactorService
{
    public function applyCorporateActionFactor(DataQualityIssue $issue, float $appliedRatio): PriceAdjustmentFactor
    {
        $stock = $issue->stock;
        if (! $stock instanceof Stock) {
            throw new \InvalidArgumentException('Issue has no stock attached.');
        }

        $actionType = $issue->corporate_action_type ?? 'split';
        $priceDivisor = $actionType === 'bonus' ? (1 + $appliedRatio) : $appliedRatio;
        $volumeMultiplier = $priceDivisor;

        return PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id,
            'issue_id' => $issue->id,
            'factor_type' => 'corporate_action',
            'action_type' => $issue->corporate_action_type,
            'effective_ex_date' => $issue->ex_date?->toDateString(),
            'applied_ratio' => $appliedRatio,
            'price_divisor' => $priceDivisor,
            'volume_multiplier' => $volumeMultiplier,
            'is_active' => true,
            'applied_at' => now(),
            'metadata' => [
                'source' => 'data_quality_center',
                'detection_method' => $issue->detection_method,
                'detection_source' => $issue->detection_source,
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ]);
    }

    public function deactivateForIssue(DataQualityIssue $issue): int
    {
        return PriceAdjustmentFactor::query()
            ->where('issue_id', $issue->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'reversed_at' => now(),
            ]);
    }
}
