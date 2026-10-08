<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use InvalidArgumentException;
use App\Models\PortfolioSnapshot;
use Illuminate\Database\Eloquent\Builder;

class PortfolioSnapshotExportProvider implements StreamingExportDatasetProvider
{
    private const DATASETS = ['portfolio-growth', 'portfolio-snapshots'];

    private const COLUMNS = ['snapshot_date', 'portfolio_value', 'invested_value'];

    public function catalog(): array
    {
        $fields = [
            'snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'],
            'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'],
            'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount'],
        ];

        return [
            ['id' => 'portfolio-growth', 'label' => 'Portfolio growth chart data', 'fields' => self::COLUMNS, 'field_metadata' => $fields, 'scopes' => ['current', 'full'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
            ['id' => 'portfolio-snapshots', 'label' => 'Daily portfolio snapshots table', 'fields' => self::COLUMNS, 'field_metadata' => $fields, 'scopes' => ['current', 'full', 'selected'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
        ];
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $this->assertDataset($dataset);
        $rows = $this->rowsQuery($profile, $filters)->get(['id', 'snapshot_date', 'portfolio_value', 'invested_value']);

        return [
            'columns' => self::COLUMNS,
            'rows' => $rows->map(fn ($row) => $this->row($row))->all(),
            'identities' => $rows->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'metadata' => $this->metadata($dataset, $profile, $filters),
        ];
    }

    public function stream(string $dataset, PortfolioProfile $profile, array $filters = [], array $selectedIds = []): array
    {
        $this->assertDataset($dataset);
        $selectedIds = array_values(array_map('strval', $selectedIds));
        if (count($selectedIds) !== count(array_unique($selectedIds))) {
            throw new ExportStaleSelectionException('Selected export rows contain duplicate identities.');
        }

        $query = $this->rowsQuery($profile, $filters, $selectedIds);
        $estimatedRows = $query->count();
        if ($selectedIds !== [] && $estimatedRows !== count($selectedIds)) {
            throw new ExportStaleSelectionException('Selected export rows are stale or unavailable.');
        }

        $rows = $query->cursor()->map(fn ($row) => $this->row($row));

        return [
            'columns' => self::COLUMNS,
            'rows' => $rows,
            'estimated_rows' => $estimatedRows,
            'selection_validated' => $selectedIds !== [],
            'metadata' => $this->metadata($dataset, $profile, $filters),
        ];
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        $this->assertDataset($dataset);
        abort_unless((int) $profile->user_id === $userId, 403);
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        if (! in_array($dataset, self::DATASETS, true)) return false;

        return in_array($scope, $dataset === 'portfolio-snapshots' ? ['current', 'full', 'selected'] : ['current', 'full'], true);
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $this->assertDataset($dataset);

        return ['rows' => $this->rowsQuery($profile, $filters)->count(), 'exact' => true];
    }

    private function rowsQuery(PortfolioProfile $profile, array $filters, array $selectedIds = []): Builder
    {
        $query = PortfolioSnapshot::query()->where('profile_id', $profile->id);
        if ($selectedIds !== []) {
            $query->whereIn('id', $selectedIds);
        }

        $range = $filters['range'] ?? null;
        $limit = null;
        if ($range && $range !== 'all') {
            $days = ['90d' => 90, '180d' => 180, '365d' => 365][$range] ?? null;
            if ($days === null) throw new InvalidArgumentException('This snapshot range is not available for export.');
            $query->whereDate('snapshot_date', '>=', now()->subDays($days)->toDateString());
            $limit = $days;
        } elseif ($range === 'all') {
            $limit = 2000;
        }

        if ($limit !== null) {
            $latestIds = (clone $query)
                ->select('id')
                ->orderByDesc('snapshot_date')
                ->orderByDesc('id')
                ->limit($limit);
            $query = PortfolioSnapshot::query()->whereIn('id', $latestIds);
        }

        $direction = ($filters['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy('snapshot_date', $direction)->orderBy('id', $direction);
    }

    private function row(object $row): array
    {
        return [
            'snapshot_date' => $row->snapshot_date?->toDateString(),
            'portfolio_value' => $row->portfolio_value,
            'invested_value' => $row->invested_value,
        ];
    }

    private function metadata(string $dataset, PortfolioProfile $profile, array $filters): array
    {
        $range = $filters['range'] ?? null;
        $direction = $filters['sort_direction'] ?? 'asc';
        $resolvedFilters = $filters ? ['range' => $range, 'sort_by' => 'snapshot_date', 'sort_direction' => $direction] : [];

        return [
            'dataset' => $dataset,
            'profile_id' => $profile->id,
            'cadence' => 'daily',
            'source' => 'portfolio snapshots',
            'field_labels' => [
                'snapshot_date' => 'Snapshot date',
                'portfolio_value' => 'Portfolio value',
                'invested_value' => 'Invested value',
            ],
            ...($resolvedFilters ? ['filters' => ['range' => $range], 'sort' => ['by' => 'snapshot_date', 'direction' => $direction]] : []),
        ];
    }

    private function assertDataset(string $dataset): void
    {
        if (! in_array($dataset, self::DATASETS, true)) {
            throw new InvalidArgumentException('This dataset is not available from this provider.');
        }
    }
}
