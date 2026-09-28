<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Services\Fundamentals\FundamentalDataService;
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

        $response = $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk();

        $response->assertJsonFragment(['signal_key' => 'operating_margin_movement_expanding']);
        $response->assertJsonFragment(['category' => 'profitability']);
        $response->assertJsonFragment(['signal_key' => 'earnings_cash_divergence']);
        $response->assertJsonFragment(['signal_key' => 'debt_increasing']);
        $response->assertJsonFragment(['signal_key' => 'share_count_dilution']);
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
}
