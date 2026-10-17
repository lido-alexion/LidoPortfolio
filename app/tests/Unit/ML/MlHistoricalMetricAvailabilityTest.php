<?php

namespace Tests\Unit\ML;

use App\Services\Fundamentals\FundamentalDataService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Tests\TestCase;

class MlHistoricalMetricAvailabilityTest extends TestCase
{
    private const AS_OF = '2026-04-01';

    public function test_historical_equity_ratios_require_positive_equity_and_keep_zero_debt_valid(): void
    {
        $positive = $this->historicalMetrics(100.0, 100.0);
        $this->assertSame(-40.0, $positive['roe']);
        $this->assertSame(0.0, $positive['debt_equity']);
        $this->assertSame(0.0, $positive['net_debt_equity']);

        foreach ([0.0, -100.0, null] as $equity) {
            $metrics = $this->historicalMetrics($equity, 100.0);
            $this->assertNull($metrics['roe']);
            $this->assertNull($metrics['debt_equity']);
            $this->assertNull($metrics['net_debt_equity']);
        }

        $canonicalRatio = new \ReflectionMethod(FundamentalDataService::class, 'ratioWithPositiveDenominator');
        $service = app(FundamentalDataService::class);
        $this->assertSame(-0.4, $canonicalRatio->invoke($service, -40.0, 100.0));
        $this->assertSame(0.0, $canonicalRatio->invoke($service, 0.0, 100.0));
        foreach ([0.0, -100.0, null] as $equity) {
            $this->assertNull($canonicalRatio->invoke($service, -40.0, $equity));
        }
        $this->assertNull($canonicalRatio->invoke($service, null, 100.0));
    }

    public function test_historical_margins_require_positive_revenue_and_preserve_negative_profit(): void
    {
        $positiveRevenue = $this->historicalMetrics(100.0, 100.0);
        $this->assertSame(-10.0, $positiveRevenue['operating_margin']);
        $this->assertSame(-10.0, $positiveRevenue['net_margin']);
        $this->assertSame(-1.0, $positiveRevenue['fcf_margin']);
        $this->assertSame(-2.0, $positiveRevenue['gross_margin']);

        foreach ([0.0, -100.0, null] as $quarterRevenue) {
            $metrics = $this->historicalMetrics(100.0, $quarterRevenue);
            $this->assertNull($metrics['operating_margin']);
            $this->assertNull($metrics['net_margin']);
            $this->assertNull($metrics['fcf_margin']);
            $this->assertNull($metrics['gross_margin']);
        }

        $missingRevenue = $this->historicalMetrics(100.0, null, includeRevenue: false);
        $this->assertNull($missingRevenue['operating_margin']);
        $this->assertNull($missingRevenue['net_margin']);
        $this->assertNull($missingRevenue['fcf_margin']);
        $this->assertNull($missingRevenue['gross_margin']);

        $canonicalRatio = new \ReflectionMethod(FundamentalDataService::class, 'ratioWithPositiveDenominator');
        $service = app(FundamentalDataService::class);
        $this->assertSame(-0.1, $canonicalRatio->invoke($service, -40.0, 400.0));
        foreach ([0.0, -400.0, null] as $revenue) {
            $this->assertNull($canonicalRatio->invoke($service, -40.0, $revenue));
        }
        $this->assertNull($canonicalRatio->invoke($service, null, 400.0));
    }

    private function historicalMetrics(?float $equity, ?float $quarterRevenue, bool $includeRevenue = true): array
    {
        $builder = app(MlTrainingDatasetBuilder::class);
        $method = new \ReflectionMethod($builder, 'historicalMetrics');
        $facts = [];
        foreach ([
            '2025-03-31',
            '2025-06-30',
            '2025-09-30',
            '2025-12-31',
        ] as $periodEnd) {
            foreach ([
                'net_income' => -10.0,
                'operating_profit' => -10.0,
                'operating_cash_flow' => 4.0,
                'capital_expenditure' => 5.0,
                'gross_profit' => -2.0,
            ] as $factKey => $value) {
                $facts[] = $this->fact($factKey, $periodEnd, $value);
            }
            if ($includeRevenue && $quarterRevenue !== null) {
                $facts[] = $this->fact('revenue', $periodEnd, $quarterRevenue);
            }
        }

        $latestPeriod = '2025-12-31';
        if ($equity !== null) {
            $facts[] = $this->fact('equity', $latestPeriod, $equity);
        }
        $facts[] = $this->fact('debt', $latestPeriod, 0.0);
        $facts[] = $this->fact('cash_and_equivalents', $latestPeriod, 0.0);

        return $method->invoke($builder, $facts, self::AS_OF);
    }

    private function fact(string $factKey, string $periodEnd, float $value): array
    {
        return [
            'fact_key' => $factKey,
            'period_end' => $periodEnd,
            'availability_date' => '2026-01-15',
            'revision_number' => 1,
            'value' => $value,
        ];
    }
}
