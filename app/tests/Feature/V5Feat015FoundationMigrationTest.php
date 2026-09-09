<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class V5Feat015FoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_tax_and_evidence_foundation_is_present(): void
    {
        $expected = [
            'portfolio_benchmarks' => ['stable_key', 'return_type', 'provider', 'is_default'],
            'portfolio_analysis_preferences' => ['user_id', 'profile_id', 'primary_benchmark_id', 'include_in_account_performance', 'include_in_account_tax'],
            'portfolio_tax_rule_versions' => ['version', 'effective_from', 'rules'],
            'portfolio_opening_tax_lots' => ['profile_id', 'stock_id', 'acquired_on', 'quantity', 'cost_basis', 'reason'],
            'portfolio_tax_losses' => ['user_id', 'financial_year', 'loss_type', 'amount', 'status'],
            'portfolio_dividends' => ['user_id', 'received_on', 'amount', 'deduplication_key', 'source_evidence'],
            'portfolio_analysis_evidence' => ['id', 'calculation_type', 'calculation_mode', 'request_cutoff_at', 'completeness', 'assumptions', 'result'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
            $this->assertTrue(Schema::hasColumns($table, $columns), "Missing columns on {$table}");
        }
    }
}
