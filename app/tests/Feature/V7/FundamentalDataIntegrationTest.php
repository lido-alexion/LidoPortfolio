<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\User;
use App\Services\Fundamentals\FundamentalDataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalDataIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fundamental_revisions_are_immutable_and_point_in_time_resolved(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $service = app(FundamentalDataService::class);

        $base = [
            'provider' => 'yahoo',
            'statement_type' => 'income_statement',
            'cadence' => FundamentalDataService::CADENCE_QUARTERLY,
            'statement_basis' => 'consolidated',
            'fact_key' => 'net_income',
            'period_end' => '2026-06-30',
            'value' => 100,
            'availability_date' => '2026-07-20',
        ];

        $this->assertSame(['inserted' => 1, 'deduped' => 0, 'restated' => 0], $service->storeFacts($stock, [$base], Carbon::parse('2026-07-20')));
        $this->assertSame(['inserted' => 0, 'deduped' => 1, 'restated' => 0], $service->storeFacts($stock, [$base], Carbon::parse('2026-07-21')));
        $this->assertSame(['inserted' => 0, 'deduped' => 0, 'restated' => 1], $service->storeFacts($stock, [[...$base, 'value' => 125, 'availability_date' => '2026-08-15']], Carbon::parse('2026-08-15')));

        $this->assertSame(100.0, (float) $service->latestFact($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, Carbon::parse('2026-08-01'))->value);
        $this->assertSame(125.0, (float) $service->latestFact($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, Carbon::parse('2026-08-20'))->value);
    }

    public function test_ttm_metrics_return_null_when_required_flow_facts_are_missing(): void
    {
        $stock = Stock::query()->create(['symbol' => 'SPARSE', 'exchange' => 'NSE', 'name' => 'Sparse Fundamentals']);
        $service = app(FundamentalDataService::class);

        $service->storeFacts($stock, [[
            'provider' => 'yahoo',
            'statement_type' => 'balance_sheet',
            'cadence' => FundamentalDataService::CADENCE_QUARTERLY,
            'statement_basis' => 'consolidated',
            'fact_key' => 'equity',
            'period_end' => '2026-06-30',
            'value' => 500,
            'availability_date' => '2026-07-20',
        ]], Carbon::parse('2026-07-20'));

        $metric = $service->metric($stock, 'roe', 'ttm', Carbon::parse('2026-09-12'));

        $this->assertNull($metric['value']);
        $this->assertFalse($metric['valid_for_live_decision']);
        $this->assertSame('fresh', $metric['freshness']['status']);
    }

    public function test_admin_fundamental_routes_are_admin_only_and_expose_defaults(): void
    {
        $member = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($member);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($member)->withProfileHeader($member)
            ->getJson('/api/v1/admin/fundamentals')
            ->assertForbidden();

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/fundamentals')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.settings.quarterly_freshness_months', 5)
            ->assertJsonPath('data.settings.annual_freshness_months', 15);
    }
}
