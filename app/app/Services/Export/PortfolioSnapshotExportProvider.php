<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;

class PortfolioSnapshotExportProvider implements ExportDatasetProvider
{
    private const DATASETS = ['portfolio-growth', 'portfolio-snapshots'];

    public function catalog(): array
    {
        return [
            ['id' => 'portfolio-growth', 'label' => 'Portfolio growth chart data', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'field_metadata' => ['snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'], 'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'], 'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount']], 'scopes' => ['current', 'full'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
            ['id' => 'portfolio-snapshots', 'label' => 'Daily portfolio snapshots table', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'field_metadata' => ['snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'], 'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'], 'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount']], 'scopes' => ['current', 'full', 'selected'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
        ];
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        if (! in_array($dataset, self::DATASETS, true)) throw new \InvalidArgumentException('This dataset is not available from this provider.');
        $query = PortfolioSnapshot::query()->where('profile_id', $profile->id);
        $range = $filters['range'] ?? null;
        if ($range && $range !== 'all') {
            $days = ['90d' => 90, '180d' => 180, '365d' => 365][$range];
            $query->where('snapshot_date', '>=', now()->subDays($days)->toDateString())->limit($days);
        } elseif ($range === 'all') {
            $query->limit(2000);
        }
        $direction = $filters['sort_direction'] ?? 'asc';
        $query->orderByDesc('snapshot_date');
        $resolvedFilters = $filters ? ['range' => $range, 'sort_by' => 'snapshot_date', 'sort_direction' => $direction] : [];
        $rows = $query->get(['id', 'snapshot_date', 'portfolio_value', 'invested_value'])
            ->sortBy('snapshot_date', SORT_REGULAR, $direction === 'desc')
            ->values();

        return [
            'columns' => ['snapshot_date', 'portfolio_value', 'invested_value'],
            'rows' => $rows->map(fn ($row) => ['snapshot_date' => $row->snapshot_date?->toDateString(), 'portfolio_value' => $row->portfolio_value, 'invested_value' => $row->invested_value])->all(),
            'identities' => $rows->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'metadata' => ['dataset' => $dataset, 'profile_id' => $profile->id, 'cadence' => 'daily', 'source' => 'portfolio snapshots', 'field_labels' => ['snapshot_date' => 'Snapshot date', 'portfolio_value' => 'Portfolio value', 'invested_value' => 'Invested value'], ...($resolvedFilters ? ['filters' => ['range' => $range], 'sort' => ['by' => 'snapshot_date', 'direction' => $direction]] : [])],
        ];
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        abort_unless(in_array($dataset, self::DATASETS, true), 404);
        abort_unless((int) $profile->user_id === $userId, 403);
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        if (! in_array($dataset, self::DATASETS, true)) return false;
        return in_array($scope, $dataset === 'portfolio-snapshots' ? ['current', 'full', 'selected'] : ['current', 'full'], true);
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        if (! in_array($dataset, self::DATASETS, true)) throw new \InvalidArgumentException('This dataset is not available from this provider.');
        $query = PortfolioSnapshot::query()->where('profile_id', $profile->id);
        $range = $filters['range'] ?? null;
        if ($range && $range !== 'all') {
            $days = ['90d' => 90, '180d' => 180, '365d' => 365][$range];
            $query->where('snapshot_date', '>=', now()->subDays($days)->toDateString());
            return ['rows' => min($query->count(), $days), 'exact' => true];
        }
        $count = $query->count();
        return ['rows' => $range === 'all' ? min($count, 2000) : $count, 'exact' => true];
    }
}
