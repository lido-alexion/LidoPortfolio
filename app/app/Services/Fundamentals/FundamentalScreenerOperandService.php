<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Carbon\Carbon;

class FundamentalScreenerOperandService
{
    /** @var array<string, array{metric:string,basis:string,growth?:bool}> */
    public const OPERANDS = [
        'fund_roe_ttm' => ['metric' => 'roe', 'basis' => 'ttm'],
        'fund_pe_ttm' => ['metric' => 'pe', 'basis' => 'ttm'],
        'fund_pb' => ['metric' => 'pb', 'basis' => 'ttm'],
        'fund_debt_equity' => ['metric' => 'debt_equity', 'basis' => 'ttm'],
        'fund_revenue_ttm' => ['metric' => 'revenue', 'basis' => 'ttm'],
        'fund_net_income_ttm' => ['metric' => 'net_income', 'basis' => 'ttm'],
        'fund_fcf_ttm' => ['metric' => 'free_cash_flow', 'basis' => 'ttm'],
        'fund_revenue_growth_yoy' => ['metric' => 'revenue', 'basis' => 'quarterly', 'growth' => true],
    ];

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalInvestorSnapshotService $snapshots,
    ) {}

    public function supports(string $indicatorId): bool
    {
        return isset(self::OPERANDS[$indicatorId]);
    }

    public function valueForIndicator(Stock $stock, string $indicatorId, Carbon $asOf): ?float
    {
        $config = self::OPERANDS[$indicatorId] ?? null;
        if ($config === null) {
            return null;
        }

        if (! empty($config['growth'])) {
            $growth = $this->fundamentals->growthMetric(
                $stock,
                $config['metric'],
                FundamentalDataService::CADENCE_QUARTERLY,
                $asOf,
            );

            return $growth['value'] !== null ? (float) $growth['value'] : null;
        }

        $price = $this->snapshots->latestMarketPrice($stock, $asOf);
        $row = $this->fundamentals->metric(
            $stock,
            $config['metric'],
            $config['basis'],
            $asOf,
            is_numeric($price) ? (float) $price : null,
        );

        return $row['value'] !== null ? (float) $row['value'] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalogRows(): array
    {
        $catalog = app(FundamentalMetricCatalog::class);
        $operandMetric = [
            'fund_roe_ttm' => 'roe',
            'fund_pe_ttm' => 'pe',
            'fund_pb' => 'pb',
            'fund_debt_equity' => 'debt_equity',
            'fund_revenue_ttm' => 'revenue',
            'fund_net_income_ttm' => 'net_income',
            'fund_fcf_ttm' => 'free_cash_flow',
            'fund_revenue_growth_yoy' => 'revenue_growth_yoy',
        ];
        $labels = [];
        foreach ($operandMetric as $operandId => $metricId) {
            $labels[$operandId] = $catalog->derivedMetricLabel($metricId);
        }

        $rows = [];
        foreach (self::OPERANDS as $id => $_) {
            $rows[] = [
                'id' => $id,
                'label' => $labels[$id] ?? $id,
                'params' => [],
                'min_bars' => 1,
                'needs_volume' => false,
                'category' => 'fundamental',
            ];
        }

        return $rows;
    }
}
