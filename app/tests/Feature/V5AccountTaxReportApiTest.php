<?php

namespace Tests\Feature;

use App\Models\AnalysisPreference;
use App\Models\Dividend;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\TaxRuleVersion;
use App\Models\TaxLoss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5AccountTaxReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_report_uses_fifo_realized_gains_and_separate_dividends(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'TAX', 'exchange' => 'NSE', 'name' => 'Tax Test']);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 10, 'price' => 100, 'fees' => 0, 'transaction_date' => '2024-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'sell',
            'quantity' => 5, 'price' => 150, 'fees' => 0, 'transaction_date' => '2025-05-01',
        ]);
        Dividend::query()->create([
            'user_id' => $user->id, 'received_on' => '2025-06-01', 'amount' => 25,
            'currency' => 'INR', 'source' => 'manual', 'deduplication_key' => 'tax-report-test',
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/tax/report?financial_year=2025-26')
            ->assertOk()
            ->assertJsonPath('data.calculation_mode', 'configured')
            ->assertJsonPath('data.summary.long_term_realized_gain', 250)
            ->assertJsonPath('data.summary.short_term_realized_gain', 0)
            ->assertJsonPath('data.summary.dividend_income', 25)
            ->assertJsonPath('data.summary.estimated_tax', null)
            ->assertJsonPath('data.assumptions.lot_method', 'fifo');
    }

    public function test_what_if_is_explicit_and_does_not_mutate_configured_inclusion(): void
    {
        $user = User::factory()->create();
        $included = $this->defaultPortfolioFor($user);
        $excluded = $user->portfolios()->create(['name' => 'Excluded']);
        AnalysisPreference::query()->create([
            'user_id' => $user->id,
            'scope_key' => 'portfolio:'.$excluded->id,
            'profile_id' => $excluded->id,
            'include_in_account_performance' => true,
            'include_in_account_tax' => false,
        ]);

        $this->actingAs($user)->withProfileHeader($user, $included)
            ->getJson('/api/tax/report?financial_year=2025-26')
            ->assertOk()->assertJsonPath('data.portfolio_ids', [$included->id]);
        $this->actingAs($user)->withProfileHeader($user, $included)
            ->getJson('/api/tax/report?financial_year=2025-26&portfolio_ids[]='.$excluded->id)
            ->assertOk()
            ->assertJsonPath('data.calculation_mode', 'what_if')
            ->assertJsonPath('data.portfolio_ids', [$excluded->id]);

        $this->assertFalse(AnalysisPreference::query()->where('profile_id', $excluded->id)->firstOrFail()->include_in_account_tax);
    }

    public function test_what_if_rejects_another_accounts_portfolio(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/tax/report?financial_year=2025-26&portfolio_ids[]='.$otherProfile->id)
            ->assertUnprocessable();
    }

    public function test_estimated_tax_requires_and_records_effective_rule_version(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'RULE', 'exchange' => 'NSE', 'name' => 'Rule Test']);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2024-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'sell',
            'quantity' => 1, 'price' => 200, 'fees' => 0, 'transaction_date' => '2025-05-01',
        ]);
        TaxRuleVersion::query()->create([
            'version' => 'test-rule-1', 'effective_from' => '2025-04-01',
            'rules' => [
                'long_term_holding_days' => 365, 'short_term_rate' => 0.2,
                'long_term_rate' => 0.1, 'long_term_exemption' => 0, 'fee_classifications' => [],
            ],
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/tax/report?financial_year=2025-26')
            ->assertOk()
            ->assertJsonPath('data.summary.estimated_tax', 10)
            ->assertJsonPath('data.tax_rule_versions.0', 'test-rule-1')
            ->assertJsonPath('data.completeness', 'complete');
    }

    public function test_confirmed_carryforward_loss_is_applied_only_by_versioned_setoff_rule(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'LOSS', 'exchange' => 'NSE', 'name' => 'Loss Setoff']);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2025-04-02',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'sell',
            'quantity' => 1, 'price' => 200, 'fees' => 0, 'transaction_date' => '2025-05-01',
        ]);
        TaxLoss::query()->create([
            'user_id' => $user->id, 'financial_year' => '2024-25', 'loss_type' => 'short_term',
            'amount' => 40, 'status' => 'confirmed', 'created_by' => $user->id,
        ]);
        TaxRuleVersion::query()->create([
            'version' => 'setoff-test', 'effective_from' => '2025-04-01',
            'rules' => [
                'long_term_holding_days' => 365, 'short_term_rate' => 0.2, 'long_term_rate' => 0.1,
                'long_term_exemption' => 0, 'fee_classifications' => [],
                'loss_setoff' => [
                    'short_term_against' => ['short_term', 'long_term'],
                    'long_term_against' => ['long_term'],
                    'carry_forward_years' => 8,
                ],
            ],
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/tax/report?financial_year=2025-26')
            ->assertOk()
            ->assertJsonPath('data.summary.estimated_tax', 12)
            ->assertJsonCount(1, 'data.confirmed_carryforward_losses');
    }
}
