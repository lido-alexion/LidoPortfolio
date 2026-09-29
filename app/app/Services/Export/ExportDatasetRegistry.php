<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Services\PortfolioCalculationService;
use Illuminate\Support\Arr;

class ExportDatasetRegistry
{
    public function __construct(private PortfolioCalculationService $portfolio) {}

    public function catalog(): array
    {
        return [
            ['id' => 'dashboard-summary', 'label' => 'Dashboard summary', 'fields' => ['field', 'value']],
            ['id' => 'portfolio-growth', 'label' => 'Portfolio growth chart data', 'fields' => ['snapshot_date', 'portfolio_value', 'invested_value']],
        ];
    }

    public function resolve(string $dataset, PortfolioProfile $profile): array
    {
        return match ($dataset) {
            'dashboard-summary' => $this->summary($profile),
            'portfolio-growth' => ['columns' => ['snapshot_date', 'portfolio_value', 'invested_value'], 'rows' => PortfolioSnapshot::query()->where('profile_id', $profile->id)->orderBy('snapshot_date')->limit(3650)->get(['snapshot_date', 'portfolio_value', 'invested_value'])->map(fn ($row) => $row->toArray())->all(), 'metadata' => ['dataset' => $dataset, 'profile_id' => $profile->id, 'cadence' => 'daily']],
            default => throw new \InvalidArgumentException('This dataset is not available for export.'),
        };
    }

    private function summary(PortfolioProfile $profile): array
    {
        $summary = $this->portfolio->calculateForProfile($profile);
        return ['columns' => ['field', 'value'], 'rows' => collect(Arr::only($summary, ['portfolio_value', 'invested_value', 'total_gain_loss', 'unrealized_profit', 'realized_profit', 'xirr']))->map(fn ($value, $field) => ['field' => $field, 'value' => $value])->values()->all(), 'metadata' => ['dataset' => 'dashboard-summary', 'profile_id' => $profile->id]];
    }
}
