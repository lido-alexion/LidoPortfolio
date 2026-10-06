<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Services\Fundamentals\FundamentalMetricCatalog;
use App\Services\Fundamentals\FundamentalScreenerOperandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalMetricCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_primary_facts_remain_catalogued_but_are_not_screener_operands(): void
    {
        $catalog = app(FundamentalMetricCatalog::class);
        $derivedMetricIds = collect($catalog->derivedMetrics())->pluck('id')->all();
        $this->assertContains('gross_npa_ratio', $derivedMetricIds);
        $this->assertContains('net_interest_margin', $derivedMetricIds);

        $unavailableFacts = [
            'capital_work_in_progress', 'gross_npa', 'gross_npa_ratio', 'net_npa', 'net_npa_ratio',
            'capital_adequacy_ratio', 'provisions', 'net_interest_margin', 'promoter_holding',
            'fii_holding', 'dii_holding', 'public_holding', 'promoter_pledge',
        ];
        $configuredOperandMetrics = array_column(FundamentalScreenerOperandService::OPERANDS, 'metric');
        $this->assertSame([], array_values(array_intersect($unavailableFacts, $configuredOperandMetrics)));

        $payload = $catalog->toArray();
        $operandIds = collect($payload['screener_operands'])->pluck('id')->all();
        $this->assertNotContains('fund_gross_npa_ratio', $operandIds);
        $this->assertNotContains('fund_net_npa_ratio', $operandIds);
        $this->assertNotContains('fund_capital_adequacy_ratio', $operandIds);
        $this->assertNotContains('fund_net_interest_margin', $operandIds);
    }

    public function test_authenticated_user_can_fetch_metric_catalog_api(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson('/api/v1/fundamentals/metric-catalog')
            ->assertOk()
            ->assertJsonPath('data.catalog_version', 'v8-fundamentals-catalog-1')
            ->assertJsonFragment(['id' => 'gross_npa_ratio']);
    }

    public function test_admin_can_fetch_same_catalog(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/fundamentals/metric-catalog')
            ->assertOk()
            ->assertJsonStructure(['data' => ['primary_facts', 'derived_metrics', 'screener_operands', 'chart_defaults']]);
    }

    public function test_valuation_metrics_default_to_monthly_chart_frequency(): void
    {
        $catalog = app(FundamentalMetricCatalog::class);
        $this->assertTrue($catalog->isValuationMetric('pe'));
        $this->assertSame('monthly', $catalog->defaultChartFrequency('pe'));
        $this->assertSame('quarterly', $catalog->defaultChartFrequency('roe'));
    }
}
