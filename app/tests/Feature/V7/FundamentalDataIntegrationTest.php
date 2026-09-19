<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\User;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\YahooFundamentalNormalizer;
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

    public function test_unchanged_fallback_availability_deduplicates_and_preserves_first_observation(): void
    {
        $stock = Stock::query()->create(['symbol' => 'FALLBACK', 'exchange' => 'NSE', 'name' => 'Fallback']);
        $service = app(FundamentalDataService::class);
        $row = [
            'provider' => 'yahoo',
            'statement_type' => 'income_statement',
            'cadence' => FundamentalDataService::CADENCE_QUARTERLY,
            'statement_basis' => 'consolidated',
            'fact_key' => 'revenue',
            'period_end' => '2026-06-30',
            'value' => 100,
        ];

        $first = $service->storeFacts($stock, [$row], Carbon::parse('2026-09-20'));
        $second = $service->storeFacts($stock, [array_merge($row, ['value' => 100.000000])], Carbon::parse('2026-09-23'));

        $fact = \App\Models\V7\FundamentalFact::query()->firstOrFail();
        $this->assertSame(['inserted' => 1, 'deduped' => 0, 'restated' => 0], $first);
        $this->assertSame(['inserted' => 0, 'deduped' => 1, 'restated' => 0], $second);
        $this->assertSame(1, \App\Models\V7\FundamentalFact::query()->count());
        $this->assertSame('2026-09-20', $fact->availability_date->toDateString());
        $this->assertSame(1, $fact->revision_number);
    }

    public function test_value_change_and_reversion_create_current_revisions_a_b_a(): void
    {
        $stock = Stock::query()->create(['symbol' => 'REVERT', 'exchange' => 'NSE', 'name' => 'Revert']);
        $service = app(FundamentalDataService::class);
        $row = [
            'provider' => 'yahoo',
            'statement_type' => 'income_statement',
            'cadence' => FundamentalDataService::CADENCE_ANNUAL,
            'statement_basis' => 'consolidated',
            'fact_key' => 'revenue',
            'period_end' => '2025-12-31',
        ];

        $service->storeFacts($stock, [$row + ['value' => 100]], Carbon::parse('2026-09-20'));
        $service->storeFacts($stock, [$row + ['value' => 120]], Carbon::parse('2026-09-21'));
        $service->storeFacts($stock, [$row + ['value' => 100.0]], Carbon::parse('2026-09-22'));

        $facts = \App\Models\V7\FundamentalFact::query()->orderBy('revision_number')->get();
        $this->assertCount(3, $facts);
        $this->assertSame([1, 2, 3], $facts->pluck('revision_number')->all());
        $this->assertSame([false, false, true], $facts->pluck('is_current')->all());
        $this->assertSame([100.0, 120.0, 100.0], $facts->map(fn ($fact): float => (float) $fact->value)->all());
        $this->assertSame('2026-09-22', $facts->last()->availability_date->toDateString());
    }

    public function test_yfinance_share_mapping_excludes_buyback_amounts_and_pb_never_uses_them(): void
    {
        $stock = Stock::query()->create(['symbol' => 'SHARES', 'exchange' => 'NSE', 'name' => 'Shares']);
        $rows = (new YahooFundamentalNormalizer)->normalizeYfinance([
            'statements' => [
                'income_statement' => [],
                'balance_sheet' => [[
                    'period_end' => '2026-06-30',
                    'facts' => [
                        'Stockholders Equity' => 100,
                        'Ordinary Shares Number' => 10,
                    ],
                ]],
                'cash_flow' => [[
                    'period_end' => '2026-06-30',
                    'facts' => ['Repurchase Of Capital Stock' => -1000],
                ]],
            ],
        ], FundamentalDataService::CADENCE_ANNUAL);

        $this->assertSame(['equity', 'shares_outstanding'], collect($rows)->pluck('fact_key')->sort()->values()->all());
        $this->assertNotContains('Repurchase Of Capital Stock', collect($rows)->pluck('fact_key')->all());
        $legacyRows = (new YahooFundamentalNormalizer)->normalize([
            'balanceSheetHistory' => ['balanceSheetStatements' => [[
                'endDate' => ['raw' => 1782777600],
                'commonStock' => ['raw' => 1000],
            ]]],
        ], FundamentalDataService::CADENCE_ANNUAL);
        $this->assertNotContains('shares_outstanding', collect($legacyRows)->pluck('fact_key')->all());
        app(FundamentalDataService::class)->storeFacts($stock, $rows, Carbon::parse('2026-09-20'));

        $metric = app(FundamentalDataService::class)->metric($stock, 'pb', 'annual', Carbon::parse('2026-09-20'), 20);
        $this->assertSame(2.0, $metric['value']);
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
