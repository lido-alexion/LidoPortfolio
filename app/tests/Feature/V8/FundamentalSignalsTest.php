<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalSignalsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fundamentals_include_insights_flag_returns_deterministic_signals(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'SIG', 'exchange' => 'NSE', 'name' => 'Signal Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);
        foreach (['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'net_income',
                'period_end' => $period,
                'value' => 4,
                'availability_date' => '2025-06-01',
            ]]);
        }
        $service->storeFacts($stock, [[
            'statement_type' => 'balance_sheet',
            'cadence' => 'quarterly',
            'fact_key' => 'equity',
            'period_end' => '2025-03-31',
            'value' => 100,
            'availability_date' => '2025-06-01',
        ]]);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk()
            ->assertJsonPath('data.insights.positive_signals.0.signal_key', 'roe_strong');
    }

    public function test_weak_ocf_vs_net_income_surfaces_risk_signal(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'OCF', 'exchange' => 'NSE', 'name' => 'OCF Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);
        foreach (['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [
                [
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'net_income',
                    'period_end' => $period,
                    'value' => 10,
                    'availability_date' => '2025-06-01',
                ],
                [
                    'statement_type' => 'cash_flow',
                    'cadence' => 'quarterly',
                    'fact_key' => 'operating_cash_flow',
                    'period_end' => $period,
                    'value' => 1,
                    'availability_date' => '2025-06-01',
                ],
            ]);
        }

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk()
            ->assertJsonPath('data.insights.risk_signals.0.signal_key', 'weak_ocf_vs_net_income');
    }

    public function test_receivables_growth_vs_revenue_surfaces_risk_signal(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'RCV', 'exchange' => 'NSE', 'name' => 'Recv Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);

        $periods = [
            ['2024-03-31', 100.0, 50.0],
            ['2025-03-31', 110.0, 90.0],
        ];
        foreach ($periods as [$period, $revenue, $receivables]) {
            $service->storeFacts($stock, [
                [
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'revenue',
                    'period_end' => $period,
                    'value' => $revenue,
                    'availability_date' => '2025-06-01',
                ],
                [
                    'statement_type' => 'balance_sheet',
                    'cadence' => 'quarterly',
                    'fact_key' => 'trade_receivables',
                    'period_end' => $period,
                    'value' => $receivables,
                    'availability_date' => '2025-06-01',
                ],
            ]);
        }

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk()
            ->assertJsonPath('data.insights.risk_signals.0.signal_key', 'receivables_growth_vs_revenue')
            ->assertJsonPath('data.insights.follow_up_checks.0', 'Review receivables ageing, customer concentration, bad-debt provisions and related-party notes.');
    }

    public function test_include_ai_insights_returns_disabled_when_feature_off(): void
    {
        config(['fundamentals_ai.enabled' => false]);
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'AI', 'exchange' => 'NSE', 'name' => 'AI Co']);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk()
            ->assertJsonPath('data.insights.ai.status', 'disabled');
    }

    public function test_include_ai_insights_fail_open_when_enabled_but_unconfigured(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.gemini.api_key' => null,
            'fundamentals_ai.codex.api_key' => null,
        ]);
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'AIO', 'exchange' => 'NSE', 'name' => 'AI Off']);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk()
            ->assertJsonPath('data.insights.ai.status', 'unavailable');
    }

    public function test_catalogue_surfaces_margin_cash_leverage_and_dilution_evidence(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'CAT', 'exchange' => 'NSE', 'name' => 'Catalogue Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2024-03-31', 100, 20, 10, 20, 100, 100],
            ['2025-03-31', 200, 60, 30, -5, 130, 110],
        ] as [$period, $revenue, $operatingProfit, $netIncome, $ocf, $debt, $shares]) {
            $service->storeFacts($stock, [
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue', 'period_end' => $period, 'value' => $revenue, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'operating_profit', 'period_end' => $period, 'value' => $operatingProfit, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income', 'period_end' => $period, 'value' => $netIncome, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'cash_flow', 'cadence' => 'quarterly', 'fact_key' => 'operating_cash_flow', 'period_end' => $period, 'value' => $ocf, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'debt', 'period_end' => $period, 'value' => $debt, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'shares_outstanding', 'period_end' => $period, 'value' => $shares, 'availability_date' => '2025-06-01'],
            ]);
        }
        $service->storeFacts($stock, [
            ['statement_type' => 'ownership', 'cadence' => 'quarterly', 'fact_key' => 'promoter_holding', 'period_end' => '2024-03-31', 'value' => 40, 'availability_date' => '2025-06-01'],
            ['statement_type' => 'ownership', 'cadence' => 'quarterly', 'fact_key' => 'promoter_holding', 'period_end' => '2025-03-31', 'value' => 45, 'availability_date' => '2025-06-01'],
        ]);

        $response = $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk();

        $response->assertJsonFragment(['signal_key' => 'operating_margin_movement_expanding']);
        $response->assertJsonFragment(['category' => 'profitability']);
        $response->assertJsonFragment(['signal_key' => 'earnings_cash_divergence']);
        $response->assertJsonFragment(['signal_key' => 'debt_increasing']);
        $response->assertJsonFragment(['signal_key' => 'share_count_dilution']);
        $response->assertJsonFragment(['signal_key' => 'ownership_promoter_holding_movement']);
        $response->assertJsonFragment(['basis' => 'quarterly_yoy']);
        $response->assertJsonFragment(['delta_pp' => 10]);
    }

    public function test_margin_signal_requires_exact_comparable_period(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'GAP', 'exchange' => 'NSE', 'name' => 'Gap Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2024-02-29', 100, 20],
            ['2025-03-31', 200, 60],
        ] as [$period, $revenue, $profit]) {
            $service->storeFacts($stock, [
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue', 'period_end' => $period, 'value' => $revenue, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'operating_profit', 'period_end' => $period, 'value' => $profit, 'availability_date' => '2025-06-01'],
            ]);
        }
        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk()
            ->assertJsonMissing(['signal_key' => 'operating_margin_movement_expanding']);
    }

    public function test_revenue_growth_acceleration_requires_two_valid_yoy_comparisons(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'ACC', 'exchange' => 'NSE', 'name' => 'Acceleration Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2023-03-31', 100], ['2024-03-31', 120],
            ['2023-06-30', 100], ['2024-06-30', 120],
            ['2025-03-31', 144], ['2025-06-30', 180],
        ] as [$period, $value]) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue',
                'period_end' => $period, 'value' => $value, 'availability_date' => '2025-07-01',
            ]]);
        }
        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-07-10")
            ->assertOk()
            ->assertJsonFragment(['signal_key' => 'revenue_growth_accelerating'])
            ->assertJsonFragment(['basis' => 'quarterly_yoy_trend']);
    }

    public function test_sufficiency_weights_stale_fallback_and_sparse_evidence(): void
    {
        $stock = Stock::query()->create(['symbol' => 'STALE', 'exchange' => 'NSE', 'name' => 'Stale Co']);
        $service = app(FundamentalDataService::class);
        foreach (['2024-03-31', '2024-06-30', '2024-09-30', '2024-12-31'] as $period) {
            $service->storeFacts($stock, [
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue', 'period_end' => $period, 'value' => 100, 'availability_date' => '2025-01-01', 'provider' => 'yahoo'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income', 'period_end' => $period, 'value' => 10, 'availability_date' => '2025-01-01', 'provider' => 'yahoo'],
                ['statement_type' => 'cash_flow', 'cadence' => 'quarterly', 'fact_key' => 'operating_cash_flow', 'period_end' => $period, 'value' => 12, 'availability_date' => '2025-01-01', 'provider' => 'yahoo'],
            ]);
        }

        $insights = app(FundamentalSignalsService::class)->deterministicInsights($stock, Carbon::parse('2026-06-01'));

        $this->assertContains('stale_revenue', $insights['data_sufficiency']['quality_factors']);
        $this->assertContains('fallback_provider_revenue', $insights['data_sufficiency']['quality_factors']);
        $this->assertLessThan(1.0, $insights['data_sufficiency']['score']);
        $this->assertContains('missing:debt history', array_keys($insights['data_sufficiency']['weighting']));
    }

    public function test_net_debt_direction_and_persistent_fcf_trend_are_deterministic(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TREND', 'exchange' => 'NSE', 'name' => 'Trend Co']);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2023-03-31', 100, 20], ['2024-03-31', 120, 10],
            ['2024-06-30', 120, 10], ['2025-03-31', 140, -20],
            ['2025-06-30', 140, -20],
        ] as [$period, $debt, $fcf]) {
            $service->storeFacts($stock, [
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'debt', 'period_end' => $period, 'value' => $debt, 'availability_date' => '2025-07-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'cash_and_equivalents', 'period_end' => $period, 'value' => 100, 'availability_date' => '2025-07-01'],
                ['statement_type' => 'cash_flow', 'cadence' => 'quarterly', 'fact_key' => 'free_cash_flow', 'period_end' => $period, 'value' => $fcf, 'availability_date' => '2025-07-01'],
            ]);
        }

        $insights = app(FundamentalSignalsService::class)->deterministicInsights($stock, Carbon::parse('2025-07-10'));
        $keys = collect(array_merge($insights['positive_signals'], $insights['risk_signals']))
            ->pluck('signal_key')->all();

        $this->assertContains('net_debt_increasing', $keys);
        $this->assertContains('fcf_deteriorating', $keys);
    }

    public function test_roe_movement_uses_same_period_comparable_equity(): void
    {
        $stock = Stock::query()->create(['symbol' => 'ROET', 'exchange' => 'NSE', 'name' => 'ROE Trend Co']);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2024-03-31', 10, 100],
            ['2025-03-31', 20, 100],
        ] as [$period, $income, $equity]) {
            $service->storeFacts($stock, [
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income', 'period_end' => $period, 'value' => $income, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'equity', 'period_end' => $period, 'value' => $equity, 'availability_date' => '2025-06-01'],
            ]);
        }

        $insights = app(FundamentalSignalsService::class)->deterministicInsights($stock, Carbon::parse('2025-06-20'));
        $keys = collect($insights['positive_signals'])->pluck('signal_key')->all();

        $this->assertContains('roe_movement_expanding', $keys);
    }

    public function test_operating_profit_bottom_line_and_working_capital_signals_use_comparable_periods(): void
    {
        $stock = Stock::query()->create(['symbol' => 'WCV', 'exchange' => 'NSE', 'name' => 'Working Capital Co']);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2024-03-31', 100, 10, 10, 20, 30, 40],
            ['2025-03-31', 110, 40, 5, 45, 50, 40],
        ] as [$period, $revenue, $operatingProfit, $netIncome, $receivables, $inventory, $currentLiabilities]) {
            $service->storeFacts($stock, [
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue', 'period_end' => $period, 'value' => $revenue, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'operating_profit', 'period_end' => $period, 'value' => $operatingProfit, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income', 'period_end' => $period, 'value' => $netIncome, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'trade_receivables', 'period_end' => $period, 'value' => $receivables, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'inventory', 'period_end' => $period, 'value' => $inventory, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'current_liabilities', 'period_end' => $period, 'value' => $currentLiabilities, 'availability_date' => '2025-06-01'],
            ]);
        }

        $insights = app(FundamentalSignalsService::class)->deterministicInsights($stock, Carbon::parse('2025-06-20'));
        $keys = collect(array_merge($insights['risk_signals'], $insights['watch_items']))->pluck('signal_key')->all();

        $this->assertContains('operating_profit_bottom_line_divergence', $keys);
        $this->assertContains('working_capital_absorption', $keys);
    }

    public function test_roa_and_roce_movement_require_positive_comparable_denominators(): void
    {
        $stock = Stock::query()->create(['symbol' => 'ROA', 'exchange' => 'NSE', 'name' => 'Returns Co']);
        $service = app(FundamentalDataService::class);
        foreach ([
            ['2024-03-31', 10, 100, 20, 100],
            ['2025-03-31', 20, 100, 50, 100],
        ] as [$period, $income, $assets, $operatingProfit, $capitalEmployed]) {
            $service->storeFacts($stock, [
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income', 'period_end' => $period, 'value' => $income, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'total_assets', 'period_end' => $period, 'value' => $assets, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'operating_profit', 'period_end' => $period, 'value' => $operatingProfit, 'availability_date' => '2025-06-01'],
                ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'capital_employed', 'period_end' => $period, 'value' => $capitalEmployed, 'availability_date' => '2025-06-01'],
            ]);
        }

        $insights = app(FundamentalSignalsService::class)->deterministicInsights($stock, Carbon::parse('2025-06-20'));
        $keys = collect($insights['positive_signals'])->pluck('signal_key')->all();

        $this->assertContains('roa_movement_expanding', $keys);
        $this->assertContains('roce_movement_expanding', $keys);
    }

    public function test_current_ratio_change_is_unavailable_when_periods_do_not_match(): void
    {
        $stock = Stock::query()->create(['symbol' => 'LIQ', 'exchange' => 'NSE', 'name' => 'Liquidity Co']);
        $service = app(FundamentalDataService::class);
        $service->storeFacts($stock, [
            ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'current_assets', 'period_end' => '2024-03-31', 'value' => 100, 'availability_date' => '2025-06-01'],
            ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'current_assets', 'period_end' => '2025-03-31', 'value' => 150, 'availability_date' => '2025-06-01'],
            ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'current_liabilities', 'period_end' => '2024-02-29', 'value' => 100, 'availability_date' => '2025-06-01'],
            ['statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'current_liabilities', 'period_end' => '2025-03-31', 'value' => 50, 'availability_date' => '2025-06-01'],
        ]);

        $insights = app(FundamentalSignalsService::class)->deterministicInsights($stock, Carbon::parse('2025-06-20'));
        $this->assertNotContains('liquidity_coverage_improving', collect($insights['watch_items'])->pluck('signal_key')->all());
        $this->assertNotContains('liquidity_coverage_deteriorating', collect($insights['risk_signals'])->pluck('signal_key')->all());
    }
}
