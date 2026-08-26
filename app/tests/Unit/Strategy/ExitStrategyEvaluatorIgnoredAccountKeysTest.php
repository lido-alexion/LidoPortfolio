<?php

namespace Tests\Unit\Strategy;

use App\Engines\Strategy\ExitStrategyEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * V3 WS-PS-A — legacy account-level exit keys must not fire as strategy_exit.
 */
class ExitStrategyEvaluatorIgnoredAccountKeysTest extends TestCase
{
    public function test_max_loss_does_not_trigger_strategy_exit(): void
    {
        $result = ExitStrategyEvaluator::evaluate(
            [
                'enabled' => true,
                'mode' => 'any',
                'rules' => [
                    ['key' => 'max_loss', 'enabled' => true, 'value' => 8],
                ],
            ],
            ['unrealized_pnl_pct' => -20.0, 'overall_score' => 90],
        );

        $this->assertFalse($result['triggered']);
        $this->assertSame([], $result['matched']);
        $this->assertSame('Not Triggered', $result['status']);
    }

    public function test_trailing_stop_proxy_does_not_trigger_strategy_exit(): void
    {
        $result = ExitStrategyEvaluator::evaluate(
            [
                'enabled' => true,
                'mode' => 'any',
                'rules' => [
                    ['key' => 'trailing_stop', 'enabled' => true, 'value' => 10],
                ],
            ],
            ['unrealized_pnl_pct' => -25.0, 'overall_score' => 90],
        );

        $this->assertFalse($result['triggered']);
        $this->assertSame([], $result['matched']);
    }

    public function test_strategy_specific_score_exit_still_triggers(): void
    {
        $result = ExitStrategyEvaluator::evaluate(
            [
                'enabled' => true,
                'mode' => 'any',
                'rules' => [
                    ['key' => 'max_loss', 'enabled' => true, 'value' => 8],
                    ['key' => 'trailing_stop', 'enabled' => true, 'value' => 10],
                    ['key' => 'score_exit', 'enabled' => true, 'value' => 20],
                ],
            ],
            [
                'unrealized_pnl_pct' => -50.0,
                'overall_score' => 10,
            ],
        );

        $this->assertTrue($result['triggered']);
        $this->assertCount(1, $result['matched']);
        $this->assertSame('score_exit', $result['matched'][0]['key']);
    }

    public function test_merge_with_defaults_omits_legacy_account_keys(): void
    {
        $merged = ExitStrategyEvaluator::mergeWithDefaults([
            ['key' => 'max_loss', 'enabled' => true, 'value' => 8],
            ['key' => 'trailing_stop', 'enabled' => true, 'value' => 10],
            ['key' => 'score_exit', 'enabled' => true, 'value' => 15],
        ]);

        $keys = array_map(fn ($r) => $r['key'] ?? null, $merged);
        $this->assertNotContains('max_loss', $keys);
        $this->assertNotContains('trailing_stop', $keys);
        $this->assertContains('score_exit', $keys);
        $this->assertContains('ma_breakdown', $keys);
        $this->assertContains('atr_stop', $keys);
        $this->assertContains('screener_exit', $keys);

        $score = null;
        foreach ($merged as $row) {
            if (($row['key'] ?? null) === 'score_exit') {
                $score = $row;
                break;
            }
        }
        $this->assertNotNull($score);
        $this->assertSame(15, $score['value']);
    }

    public function test_default_rules_catalogue_excludes_max_loss_and_trailing_stop(): void
    {
        $keys = array_map(fn ($r) => $r['key'] ?? null, ExitStrategyEvaluator::defaultRules());
        $this->assertNotContains('max_loss', $keys);
        $this->assertNotContains('trailing_stop', $keys);
        $this->assertSame(
            ExitStrategyEvaluator::IGNORED_ACCOUNT_EXIT_KEYS,
            ['max_loss', 'trailing_stop'],
        );
    }
}
