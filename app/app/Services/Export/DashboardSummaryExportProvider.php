<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use App\Services\PortfolioCalculationService;
use Illuminate\Support\Arr;

class DashboardSummaryExportProvider implements ExportDatasetProvider
{
    private const FIELD_LABELS = [
        'portfolio_value' => 'Portfolio value',
        'invested_value' => 'Invested value',
        'total_gain_loss' => 'Total gain/loss',
        'unrealized_profit' => 'Unrealized profit',
        'realized_profit' => 'Realized profit',
        'xirr' => 'XIRR',
    ];

    public function __construct(private PortfolioCalculationService $portfolio) {}

    public function catalog(): array
    {
        return [[
            'id' => 'dashboard-summary',
            'label' => 'Dashboard summary',
            'fields' => ['field_key', 'field', 'value'],
            'field_metadata' => ['field_key' => ['label' => 'Metric key', 'canonical' => 'metric_key'], 'field' => ['label' => 'Metric', 'canonical' => 'friendly label'], 'value' => ['label' => 'Value', 'canonical' => 'calculated_value']],
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
            ->map(fn ($value, $field) => ['field_key' => $field, 'field' => self::FIELD_LABELS[$field], 'value' => $value])
            ->values();

        return [
            'columns' => ['field_key', 'field', 'value'],
            'rows' => $rows->all(),
            'identities' => $rows->pluck('field_key')->all(),
            'metadata' => ['dataset' => 'dashboard-summary', 'profile_id' => $profile->id, 'source' => 'portfolio calculation', 'field_labels' => ['field_key' => 'Metric key', 'field' => 'Metric', 'value' => 'Value']],
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
