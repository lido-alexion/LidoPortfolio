<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use App\Services\Analytics\PortfolioAnalyticsService;

class PortfolioAnalyticsExportProvider implements ExportDatasetProvider
{
    private const FIELDS = [
        'portfolio_value' => 'Portfolio value',
        'invested_value' => 'Invested value',
        'todays_pnl' => "Today's P&L",
        'todays_pnl_pct' => "Today's P&L percent",
        'total_return' => 'Total return',
        'total_return_pct' => 'Total return percent',
        'xirr' => 'XIRR',
        'portfolio_score' => 'Portfolio score',
        'portfolio_beta' => 'Portfolio beta',
        'portfolio_volatility_pct' => 'Portfolio volatility percent',
        'cash_available' => 'Available cash',
        'cash_reserved' => 'Reserved cash',
        'cash_balance' => 'Cash balance',
        'cash_utilisation_pct' => 'Cash utilisation percent',
        'diversification_score' => 'Diversification score',
        'average_relative_strength' => 'Average relative strength',
        'average_momentum_score' => 'Average momentum score',
        'average_trend_score' => 'Average trend score',
        'average_risk_score' => 'Average risk score',
        'average_holding_period_days' => 'Average holding period days',
        'number_of_positions' => 'Number of positions',
        'largest_position_pct' => 'Largest position percent',
        'concentration_index' => 'Concentration index',
    ];

    public function __construct(private PortfolioAnalyticsService $analytics) {}

    public function catalog(): array
    {
        return [[
            'id' => 'portfolio-analytics',
            'label' => 'Portfolio analytics',
            'fields' => ['metric', 'value'],
            'field_metadata' => [
                'metric' => ['label' => 'Metric', 'canonical' => 'metric_key'],
                'value' => ['label' => 'Value', 'canonical' => 'calculated_value'],
            ],
            'scopes' => ['full'],
            'formats' => ['csv', 'xlsx'],
            'estimate' => ['kind' => 'bounded', 'maximum_rows' => count(self::FIELDS)],
        ]];
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $this->assertDataset($dataset);
        $payload = $this->analytics->forProfile($profile, false);
        $rows = [];
        foreach (self::FIELDS as $key => $_label) {
            if (array_key_exists($key, $payload)) {
                $rows[] = ['metric' => $key, 'value' => $payload[$key]];
            }
        }

        return [
            'columns' => ['metric', 'value'],
            'rows' => $rows,
            'identities' => array_column($rows, 'metric'),
            'metadata' => [
                'dataset' => $dataset,
                'profile_id' => $profile->id,
                'source' => 'portfolio analytics',
                'computed_at' => $payload['computed_at'] ?? null,
                'field_labels' => ['metric' => 'Metric', 'value' => 'Value'],
            ],
        ];
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        $this->assertDataset($dataset);
        abort_unless((int) $profile->user_id === $userId, 403);
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        return $dataset === 'portfolio-analytics' && $scope === 'full';
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $this->assertDataset($dataset);

        return ['rows' => count(self::FIELDS), 'exact' => true];
    }

    private function assertDataset(string $dataset): void
    {
        if ($dataset !== 'portfolio-analytics') {
            throw new \InvalidArgumentException('This dataset is not available from this provider.');
        }
    }
}
