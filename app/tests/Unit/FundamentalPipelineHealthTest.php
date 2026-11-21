<?php

namespace Tests\Unit;

use App\Services\Fundamentals\FundamentalPipelineHealth;
use PHPUnit\Framework\TestCase;

class FundamentalPipelineHealthTest extends TestCase
{
    public function test_mixed_success_and_retryable_errors_are_operational_without_action_alert(): void
    {
        $sessions = [
            $this->session(1, [
                ['outcome' => 'data'], ['outcome' => 'provider_error', 'details' => ['retry_pending' => true, 'category' => 'connectivity']],
                ['outcome' => 'no_data'],
            ]),
        ];
        $health = (new FundamentalPipelineHealth())->evaluate($sessions);
        self::assertSame('operational_with_partial_failures', $health['status']);
        self::assertFalse($health['action_required']);
        self::assertSame(1, $health['counts']['retry_pending']);
        self::assertArrayHasKey('connectivity', $health['error_categories']);
    }

    public function test_all_no_data_is_indeterminate_and_not_a_failure(): void
    {
        $outcomes = array_fill(0, 8, ['outcome' => 'no_data']);
        $health = (new FundamentalPipelineHealth())->evaluate([$this->session(1, $outcomes)]);
        self::assertSame('indeterminate_no_data', $health['status']);
        self::assertFalse($health['action_required']);
        self::assertSame(0, $health['counts']['exhausted']);
    }

    public function test_insufficient_all_failure_sample_does_not_claim_systemic_outage(): void
    {
        $health = (new FundamentalPipelineHealth())->evaluate([
            $this->session(1, array_fill(0, 3, ['outcome' => 'provider_error', 'details' => ['retry_pending' => true, 'category' => 'rate_limit']])),
        ]);
        self::assertSame('indeterminate_insufficient_sample', $health['status']);
        self::assertFalse($health['action_required']);
        self::assertArrayHasKey('rate_limit', $health['error_categories']);
    }

    public function test_repeated_multi_symbol_exhausted_failures_are_systemic_and_actionable(): void
    {
        $sessions = [];
        foreach ([1, 2] as $runId) {
            $outcomes = [];
            foreach (range(1, 5) as $symbol) $outcomes[] = ['stock_id' => $symbol, 'outcome' => 'provider_error', 'cadences' => $symbol % 2 ? 'quarterly' : 'annual', 'details' => ['retry_pending' => true, 'category' => 'auth']];
            $sessions[] = $this->session($runId, $outcomes);
        }
        $health = (new FundamentalPipelineHealth())->evaluate($sessions);
        self::assertSame('systemic_outage', $health['status']);
        self::assertTrue($health['action_required']);
        self::assertSame(2, $health['consecutive_failed_sessions']);
        self::assertArrayHasKey('auth', $health['error_categories']);
    }

    public function test_failures_in_only_one_cadence_do_not_claim_a_pipeline_wide_outage(): void
    {
        $sessions = [];
        foreach ([1, 2] as $runId) {
            $outcomes = [];
            foreach (range(1, 5) as $symbol) $outcomes[] = ['stock_id' => $symbol, 'outcome' => 'provider_error', 'cadences' => 'quarterly', 'details' => ['retry_pending' => true, 'category' => 'connectivity']];
            $sessions[] = $this->session($runId, $outcomes);
        }
        $health = (new FundamentalPipelineHealth())->evaluate($sessions);
        self::assertSame('retry_pending', $health['status']);
        self::assertFalse($health['action_required']);
        self::assertSame(['quarterly'], $health['systemic_cadences']);
    }

    public function test_exhausted_symbol_failure_is_actionable_and_recovery_requires_two_clean_sessions(): void
    {
        $health = (new FundamentalPipelineHealth())->evaluate([
            $this->session(1, [[
                'stock_id' => 1,
                'outcome' => 'provider_error',
                'details' => ['retry_pending' => false, 'category' => 'parsing'],
            ]]),
        ]);
        self::assertTrue($health['action_required']);
        self::assertSame(0, $health['consecutive_recovery_sessions']);

        $recovered = (new FundamentalPipelineHealth())->evaluate([
            $this->session(2, [['stock_id' => 1, 'outcome' => 'data']]),
            $this->session(3, [['stock_id' => 1, 'outcome' => 'data']]),
        ]);
        self::assertSame(2, $recovered['consecutive_recovery_sessions']);
        self::assertSame('operational', $recovered['status']);
    }

    /** @param list<array<string,mixed>> $outcomes */
    private function session(int $id, array $outcomes): array
    {
        foreach ($outcomes as $index => &$outcome) $outcome['stock_id'] ??= $index + 1;
        return ['run_id' => $id, 'outcomes' => $outcomes];
    }
}
