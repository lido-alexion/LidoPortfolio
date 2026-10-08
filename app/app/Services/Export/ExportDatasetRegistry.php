<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Services\PortfolioCalculationService;
use Illuminate\Support\Arr;

class ExportDatasetRegistry implements ExportDatasetProvider
{
    public function __construct(private PortfolioCalculationService $portfolio) {}

    public function catalog(): array
    {
        return [
            ['id' => 'dashboard-summary', 'label' => 'Dashboard summary', 'fields' => ['field', 'value'], 'field_metadata' => ['field' => ['label' => 'Metric', 'canonical' => 'metric_key'], 'value' => ['label' => 'Value', 'canonical' => 'calculated_value']], 'scopes' => ['full'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'bounded', 'maximum_rows' => 6]],
            ['id' => 'portfolio-growth', 'label' => 'Portfolio growth chart data', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'field_metadata' => ['snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'], 'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'], 'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount']], 'scopes' => ['current', 'full'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
            ['id' => 'portfolio-snapshots', 'label' => 'Daily portfolio snapshots table', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'field_metadata' => ['snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'], 'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'], 'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount']], 'scopes' => ['current', 'full', 'selected'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
        ];
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        return match ($dataset) {
            'dashboard-summary' => $this->summary($profile),
            'portfolio-growth', 'portfolio-snapshots' => $this->snapshots($dataset, $profile, $filters),
            default => throw new \InvalidArgumentException('This dataset is not available for export.'),
        };
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        foreach ($this->catalog() as $definition) {
            if ($definition['id'] === $dataset) return in_array($scope, $definition['scopes'], true);
        }
        return false;
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        abort_unless((int) $profile->user_id === $userId, 403);
        abort_unless(collect($this->catalog())->contains('id', $dataset), 404);
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $definition = collect($this->catalog())->firstWhere('id', $dataset);
        if (! $definition) throw new \InvalidArgumentException('This dataset is not available for export.');
        if ($dataset === 'dashboard-summary') return ['rows' => 6, 'exact' => true];
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

    private function summary(PortfolioProfile $profile): array
    {
        $summary = $this->portfolio->calculateForProfile($profile);
        $rows = collect(Arr::only($summary, ['portfolio_value', 'invested_value', 'total_gain_loss', 'unrealized_profit', 'realized_profit', 'xirr']))->map(fn ($value, $field) => ['field' => $field, 'value' => $value])->values();
        return ['columns' => ['field', 'value'], 'rows' => $rows->all(), 'identities' => $rows->pluck('field')->all(), 'metadata' => ['dataset' => 'dashboard-summary', 'profile_id' => $profile->id, 'source' => 'portfolio calculation', 'field_labels' => ['field' => 'Metric', 'value' => 'Value']]];
    }

    private function snapshots(string $dataset, PortfolioProfile $profile, array $filters): array
    {
        $query = PortfolioSnapshot::query()->where('profile_id', $profile->id);
        $range = $filters['range'] ?? null;
        if ($range && $range !== 'all') {
            $days = ['90d' => 90, '180d' => 180, '365d' => 365][$range];
            $query->where('snapshot_date', '>=', now()->subDays($days)->toDateString());
            $query->limit($days);
        } elseif ($range === 'all') {
            $query->limit(2000);
        }
        // Match the page API: take the newest N rows first, then present the
        // same set in the requested direction (the chart is ascending).
        $direction = $filters['sort_direction'] ?? 'asc';
        $query->orderByDesc('snapshot_date');
        $resolvedFilters = $filters ? ['range' => $range, 'sort_by' => 'snapshot_date', 'sort_direction' => $direction] : [];
        $rows = $query->get(['id', 'snapshot_date', 'portfolio_value', 'invested_value'])->sortBy('snapshot_date', SORT_REGULAR, $direction === 'desc')->values();
        return [
            'columns' => ['snapshot_date', 'portfolio_value', 'invested_value'],
            'rows' => $rows->map(fn ($row) => Arr::except($row->toArray(), ['id']))->all(),
            'identities' => $rows->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'metadata' => ['dataset' => $dataset, 'profile_id' => $profile->id, 'cadence' => 'daily', 'source' => 'portfolio snapshots', 'field_labels' => ['snapshot_date' => 'Snapshot date', 'portfolio_value' => 'Portfolio value', 'invested_value' => 'Invested value'], ...($resolvedFilters ? ['filters' => ['range' => $range], 'sort' => ['by' => 'snapshot_date', 'direction' => $direction]] : [])],
        ];
    }
}
