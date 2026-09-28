<?php

namespace App\Services\Strategy;

use App\Models\Screener;
use App\Models\ScreenerVersion;
use App\Models\StrategyScreener;

/**
 * FEAT-064 — resolve immutable strategy/screener version pins for audit surfaces.
 */
class StrategyProvenanceResolutionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function pinnedScreenersForStrategyVersion(int $strategyVersionId): array
    {
        if ($strategyVersionId < 1) {
            return [];
        }

        $links = StrategyScreener::query()
            ->where('strategy_version_id', $strategyVersionId)
            ->orderBy('display_order')
            ->orderBy('priority')
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $versionIds = $links->pluck('screener_version_id')->filter()->map(fn ($id) => (int) $id)->all();
        $versions = ScreenerVersion::query()->whereIn('id', $versionIds)->get()->keyBy('id');
        $screenerIds = $links->pluck('screener_id')->map(fn ($id) => (int) $id)->all();
        $screeners = Screener::query()->whereIn('id', $screenerIds)->get()->keyBy('id');

        $out = [];
        foreach ($links as $link) {
            $screener = $screeners->get($link->screener_id);
            $version = $versions->get($link->screener_version_id);
            $out[] = [
                'screener_id' => (int) $link->screener_id,
                'screener_name' => $screener?->name,
                'screener_version_id' => $link->screener_version_id !== null ? (int) $link->screener_version_id : null,
                'semantic_version' => $version?->version !== null ? (int) $version->version : null,
                'definition_hash' => $version?->definition_hash,
                'definition_json' => $version?->definition_json,
                'scope' => $version?->scope,
                'watchlist_id' => $version?->watchlist_id,
                'index_symbol' => $version?->index_symbol,
                'priority' => (int) $link->priority,
                'enabled' => (bool) $link->enabled,
            ];
        }

        return $out;
    }
}
