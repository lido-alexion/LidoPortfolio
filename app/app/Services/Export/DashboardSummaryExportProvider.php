<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use App\Services\PortfolioCalculationService;
use Illuminate\Support\Arr;

class DashboardSummaryExportProvider implements ExportDatasetProvider
{
    public function __construct(private PortfolioCalculationService $portfolio) {}

    public function catalog(): array
    {
        return [[
            'id' => 'dashboard-summary',
            'label' => 'Dashboard summary',
            'fields' => ['field', 'value'],
            'field_metadata' => ['field' => ['label' => 'Metric', 'canonical' => 'metric_key'], 'value' => ['label' => 'Value', 'canonical' => 'calculated_value']],
            'scopes' => ['full'],
            'formats' => ['csv', 'xlsx'],
            'estimate' => ['kind' => 'bounded', 'maximum_rows' => 6],
        ]];
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        if ($dataset !== 'dashboard-summary') throw new \InvalidArgumentException('This dataset is not available from this provider.');
        $summary = $this->portfolio->calculateForProfile($profile);
        $rows = collect(Arr::only($summary, ['portfolio_value', 'invested_value', 'total_gain_loss', 'unrealized_profit', 'realized_profit', 'xirr']))
            ->map(fn ($value, $field) => ['field' => $field, 'value' => $value])
            ->values();

        return [
            'columns' => ['field', 'value'],
            'rows' => $rows->all(),
            'identities' => $rows->pluck('field')->all(),
            'metadata' => ['dataset' => 'dashboard-summary', 'profile_id' => $profile->id, 'source' => 'portfolio calculation', 'field_labels' => ['field' => 'Metric', 'value' => 'Value']],
        ];
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        abort_unless($dataset === 'dashboard-summary', 404);
        abort_unless((int) $profile->user_id === $userId, 403);
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        return $dataset === 'dashboard-summary' && $scope === 'full';
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        if ($dataset !== 'dashboard-summary') throw new \InvalidArgumentException('This dataset is not available from this provider.');
        return ['rows' => 6, 'exact' => true];
    }
}
