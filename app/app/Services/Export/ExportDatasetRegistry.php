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
            ['id' => 'dashboard-summary', 'label' => 'Dashboard summary', 'fields' => ['field', 'value'], 'field_metadata' => ['field' => ['label' => 'Metric', 'canonical' => 'metric_key'], 'value' => ['label' => 'Value', 'canonical' => 'calculated_value']], 'scopes' => ['full', 'selected'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'bounded', 'maximum_rows' => 6]],
            ['id' => 'portfolio-growth', 'label' => 'Portfolio growth chart data', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'field_metadata' => ['snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'], 'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'], 'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount']], 'scopes' => ['full', 'selected'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
            ['id' => 'portfolio-snapshots', 'label' => 'Daily portfolio snapshots table', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'field_metadata' => ['snapshot_date' => ['label' => 'Snapshot date', 'canonical' => 'YYYY-MM-DD'], 'portfolio_value' => ['label' => 'Portfolio value', 'canonical' => 'stored amount'], 'invested_value' => ['label' => 'Invested value', 'canonical' => 'stored amount']], 'scopes' => ['full', 'selected'], 'formats' => ['csv', 'xlsx'], 'estimate' => ['kind' => 'query_count']],
        ];
    }

    public function resolve(string $dataset, PortfolioProfile $profile): array
    {
        return match ($dataset) {
            'dashboard-summary' => $this->summary($profile),
            'portfolio-growth', 'portfolio-snapshots' => $this->snapshots($dataset, $profile),
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

    public function estimate(string $dataset, PortfolioProfile $profile): array
    {
        $definition = collect($this->catalog())->firstWhere('id', $dataset);
        if (! $definition) throw new \InvalidArgumentException('This dataset is not available for export.');
        if ($dataset === 'dashboard-summary') return ['rows' => 6, 'exact' => true];
        $count = PortfolioSnapshot::query()->where('profile_id', $profile->id)->count();
        return ['rows' => $count, 'exact' => true];
    }

    private function summary(PortfolioProfile $profile): array
    {
        $summary = $this->portfolio->calculateForProfile($profile);
        $rows = collect(Arr::only($summary, ['portfolio_value', 'invested_value', 'total_gain_loss', 'unrealized_profit', 'realized_profit', 'xirr']))->map(fn ($value, $field) => ['field' => $field, 'value' => $value])->values();
        return ['columns' => ['field', 'value'], 'rows' => $rows->all(), 'identities' => $rows->pluck('field')->all(), 'metadata' => ['dataset' => 'dashboard-summary', 'profile_id' => $profile->id, 'source' => 'portfolio calculation', 'field_labels' => ['field' => 'Metric', 'value' => 'Value']]];
    }

    private function snapshots(string $dataset, PortfolioProfile $profile): array
    {
        return [
            'columns' => ['snapshot_date', 'portfolio_value', 'invested_value'],
            'rows' => PortfolioSnapshot::query()->where('profile_id', $profile->id)->orderBy('snapshot_date')->get(['id', 'snapshot_date', 'portfolio_value', 'invested_value'])->map(fn ($row) => Arr::except($row->toArray(), ['id']))->all(),
            'identities' => PortfolioSnapshot::query()->where('profile_id', $profile->id)->orderBy('snapshot_date')->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'metadata' => ['dataset' => $dataset, 'profile_id' => $profile->id, 'cadence' => 'daily', 'source' => 'portfolio snapshots', 'field_labels' => ['snapshot_date' => 'Snapshot date', 'portfolio_value' => 'Portfolio value', 'invested_value' => 'Invested value']],
        ];
    }
}
