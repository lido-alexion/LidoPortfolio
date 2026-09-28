<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalBankMetricsService;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalScreenerOperandService;
use App\Services\Fundamentals\YahooFundamentalNormalizer;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalBankMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_yahoo_maps_interest_income_facts(): void
    {
        $rows = (new YahooFundamentalNormalizer)->normalizeYfinance([
            'statements' => [
                'income_statement' => [[
                    'period_end' => '2025-03-31',
                    'facts' => [
                        'Interest Income' => 1000,
                        'Interest Expense' => 400,
                        'Net Interest Income' => 600,
                    ],
                ]],
                'balance_sheet' => [],
                'cash_flow' => [],
            ],
        ], FundamentalDataService::CADENCE_QUARTERLY);

        $keys = collect($rows)->pluck('fact_key')->sort()->values()->all();
        $this->assertSame(['interest_expense', 'interest_income', 'net_interest_income'], $keys);
    }

    public function test_bank_metrics_use_reported_ratios_and_derive_nim_from_ttm_flows(): void
    {
        $stock = Stock::query()->create(['symbol' => 'BANKX', 'exchange' => 'NSE', 'name' => 'Bank X', 'sector' => 'Financial Services']);
        $service = app(FundamentalDataService::class);
        $periods = ['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'];
        foreach ($periods as $period) {
            $service->storeFacts($stock, [
                [
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'interest_income',
                    'period_end' => $period,
                    'value' => 100,
                    'availability_date' => '2025-06-01',
                ],
                [
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'net_interest_income',
                    'period_end' => $period,
                    'value' => 40,
                    'availability_date' => '2025-06-01',
                ],
            ]);
        }
        $service->storeFacts($stock, [[
            'statement_type' => 'balance_sheet',
            'cadence' => 'quarterly',
            'fact_key' => 'gross_npa_ratio',
            'period_end' => '2025-03-31',
            'value' => 2.5,
            'availability_date' => '2025-06-01',
        ]]);

        $asOf = Carbon::parse('2025-06-30');
        $bank = app(FundamentalBankMetricsService::class)->metricsForStock($stock, $asOf);
        $this->assertSame(2.5, $bank['gross_npa_ratio']);
        $this->assertNull($bank['net_npa_ratio']);
        $this->assertSame(40.0, $bank['net_interest_margin']);

        $metric = $service->metric($stock, 'gross_npa_ratio', 'quarterly', $asOf);
        $this->assertSame(2.5, (float) $metric['value']);

        $operand = app(FundamentalScreenerOperandService::class)->valueForIndicator($stock, 'fund_gross_npa_ratio', $asOf);
        $this->assertSame(2.5, $operand);
    }

    public function test_ml_feature_vector_includes_bank_columns_as_null_for_industrial(): void
    {
        $stock = Stock::query()->create(['symbol' => 'INDU', 'exchange' => 'NSE', 'name' => 'Industrial']);
        $features = app(MlTrainingDatasetBuilder::class)->featuresFor($stock, Carbon::parse('2025-06-30'));
        $this->assertArrayHasKey('gross_npa_ratio', $features);
        $this->assertNull($features['gross_npa_ratio']);
        $this->assertNull($features['net_interest_margin']);
    }

    public function test_bank_ttm_metric_rejects_missing_quarter(): void
    {
        $stock = Stock::query()->create(['symbol' => 'BANKGAP', 'exchange' => 'NSE', 'name' => 'Bank Gap']);
        $service = app(FundamentalDataService::class);
        foreach (['2024-06-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'interest_income',
                'period_end' => $period,
                'value' => 100,
                'availability_date' => '2025-06-01',
            ]]);
        }

        $this->assertNull(app(FundamentalBankMetricsService::class)
            ->metricsForStock($stock, Carbon::parse('2025-06-30'))['net_interest_margin']);
    }
}
