<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\PerformanceRiskCalculator;
use PHPUnit\Framework\TestCase;

class PerformanceRiskCalculatorTest extends TestCase
{
    public function test_twr_neutralizes_external_flows_and_links_daily_returns(): void
    {
        $result = (new PerformanceRiskCalculator)->calculate([
            ['date' => '2026-01-01', 'value' => 100.0],
            ['date' => '2026-01-02', 'value' => 160.0, 'external_flow' => 50.0],
            ['date' => '2026-01-03', 'value' => 176.0],
        ]);

        $this->assertSame(21.0, $result['twr_percent']);
        $this->assertSame('estimate_with_limitations', $result['completeness']);
        $this->assertNull($result['volatility_percent']);
        $this->assertNull($result['sharpe_ratio']);
    }

    public function test_risk_metrics_require_thirty_daily_returns_and_report_drawdown(): void
    {
        $rows = [];
        $value = 100.0;
        for ($day = 0; $day <= 30; $day++) {
            if ($day > 0) {
                $value *= $day === 15 ? 0.8 : 1.01;
            }
            $rows[] = [
                'date' => sprintf('2026-01-%02d', $day + 1),
                'value' => $value,
            ];
        }

        $result = (new PerformanceRiskCalculator)->calculate($rows, 0.06, 252);

        $this->assertSame(30, $result['observation_count']);
        $this->assertSame('complete', $result['completeness']);
        $this->assertNotNull($result['volatility_percent']);
        $this->assertNotNull($result['sharpe_ratio']);
        $this->assertLessThanOrEqual(-20.0, $result['maximum_drawdown_percent']);
    }

    public function test_unknown_value_propagates_incomplete_state(): void
    {
        $result = (new PerformanceRiskCalculator)->calculate([
            ['date' => '2026-01-01', 'value' => 100.0],
            ['date' => '2026-01-02', 'value' => null, 'complete' => false],
            ['date' => '2026-01-03', 'value' => 110.0],
        ]);

        $this->assertSame('incomplete', $result['completeness']);
        $this->assertNull($result['twr_percent']);
    }
}
