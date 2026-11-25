<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\Stock;

class DataQualityGuardService
{
    /**
     * @param  list<int>  $stockIds
     * @return array<int, bool>
     */
    public function blockedStockIdMap(array $stockIds): array
    {
        if ($stockIds === []) {
            return [];
        }

        $blocked = DataQualityIssue::query()
            ->whereIn('stock_id', $stockIds)
            ->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)
            ->pluck('stock_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $map = [];
        foreach ($blocked as $stockId) {
            $map[$stockId] = true;
        }

        foreach (app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)->quarantinedStockIds($stockIds) as $stockId => $_) {
            $map[(int) $stockId] = true;
        }

        return $map;
    }

    public function isBlockedStock(?Stock $stock): bool
    {
        if ($stock === null) {
            return false;
        }

        return isset($this->blockedStockIdMap([(int) $stock->id])[(int) $stock->id]);
    }
}
