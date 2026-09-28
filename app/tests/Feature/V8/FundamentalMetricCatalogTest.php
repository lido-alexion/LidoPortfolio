<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Services\Fundamentals\FundamentalMetricCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalMetricCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_bank_derived_metrics_and_screener_operands(): void
    {
        $catalog = app(FundamentalMetricCatalog::class);
        $ids = collect($catalog->derivedMetrics())->pluck('id')->all();
        $this->assertContains('gross_npa_ratio', $ids);
        $this->assertContains('net_interest_margin', $ids);

        $payload = $catalog->toArray();
        $operandIds = collect($payload['screener_operands'])->pluck('id')->all();
        $this->assertContains('fund_gross_npa_ratio', $operandIds);
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
