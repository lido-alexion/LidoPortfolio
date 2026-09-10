<?php

namespace Tests\Feature\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestSnapshot;
use App\Models\BacktestTrade;
use App\Models\BacktestTransaction;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Backtest\EligibilityPrecomputeService;
use App\Services\Backtest\PaperTradeExecutor;
use App\Services\Backtest\SimulationContext;
use App\Services\Backtest\SimulationDayProcessor;
use App\Services\Backtest\StatisticsGenerator;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V4-FEAT-014: Duplicate is POST /api/v1/backtests with stored inputs and no strategy_version_id.
 */
class BacktestDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_backtests_can_be_compared_without_ranking_and_with_assumption_disclosure(): void
    {
        [$user, $profile, , , $first] = $this->seedOriginalRun();
        $first->forceFill(['execution_assumptions_json' => [
            'price_method' => 'next_open', 'adverse_slippage_percent' => 0,
        ]])->save();
        $second = $first->replicate();
        $second->name = 'Comparison Run';
        $second->initial_capital = 3000000;
        $second->execution_assumptions_json = [
            'price_method' => 'next_close', 'adverse_slippage_percent' => 1,
        ];
        $second->save();

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/backtests/compare', ['run_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.compatible', true)
            ->assertJsonPath('data.compatibility.strategy_version', true)
            ->assertJsonPath('data.ranking', null)
            ->assertJsonCount(2, 'data.assumption_differences')
            ->assertJsonCount(2, 'data.runs');
    }

    public function test_pending_recommendation_executes_on_next_session_with_pinned_price_evidence(): void
    {
        [, , , , $run, $stock] = $this->seedOriginalRun();
        $run->forceFill(['execution_assumptions_json' => [
            'price_method' => 'next_open',
            'adverse_slippage_percent' => 1,
            'charge_model' => ['version' => 'test-v1', 'components' => []],
        ]])->save();
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2024-01-03',
            'open_price' => 100, 'high_price' => 110, 'low_price' => 90, 'close_price' => 105,
            'provider_source' => 'test', 'data_source' => 'test', 'created_at' => now(),
        ]);
        $ctx = SimulationContext::blank(2000, ['2024-01-02', '2024-01-03']);
        $ctx->set('pending_execution_drafts', [[
            'security_id' => $stock->id, 'symbol' => $stock->symbol,
            'action' => 'OPEN_POSITION', 'decision_date' => '2024-01-02',
            'target_amount' => 1010, 'entry_score' => 90, 'reason' => 'recommendation', 'exchange' => 'NSE',
        ]]);

        $result = app(SimulationDayProcessor::class)->executePendingDrafts($run, $ctx, new PaperTradeExecutor($ctx), '2024-01-03');

        $this->assertFalse($result['waiting']);
        $this->assertCount(1, $result['transactions']);
        $this->assertSame('2024-01-03', $result['transactions'][0]['trade_date']);
        $this->assertSame(101.0, $result['transactions'][0]['price']);
        $this->assertSame('2024-01-02', $result['transactions'][0]['meta_json']['execution_evidence']['decision_date']);
        $this->assertSame('test-v1', $result['transactions'][0]['meta_json']['execution_evidence']['charge_model_version']);
        $this->assertSame([], $ctx->get('pending_execution_drafts'));
    }

    public function test_pending_recommendation_waits_atomically_when_next_session_ohlc_is_missing(): void
    {
        [, , , , $run, $stock] = $this->seedOriginalRun();
        $run->forceFill(['execution_assumptions_json' => [
            'price_method' => 'next_open', 'adverse_slippage_percent' => 0,
            'charge_model' => ['version' => 'test-v1', 'components' => []],
        ]])->save();
        $ctx = SimulationContext::blank(2000, ['2024-01-02', '2024-01-03']);
        $pending = [[
            'security_id' => $stock->id, 'symbol' => $stock->symbol,
            'action' => 'OPEN_POSITION', 'decision_date' => '2024-01-02',
            'target_amount' => 1000, 'reason' => 'recommendation', 'exchange' => 'NSE',
        ]];
        $ctx->set('pending_execution_drafts', $pending);

        $result = app(SimulationDayProcessor::class)->executePendingDrafts($run, $ctx, new PaperTradeExecutor($ctx), '2024-01-03');

        $this->assertTrue($result['waiting']);
        $this->assertSame(['missing_session_ohlc'], $result['limitations']);
        $this->assertSame(2000.0, $ctx->cash());
        $this->assertSame([], $ctx->holdings());
        $this->assertSame($pending, $ctx->get('pending_execution_drafts'));
    }

    public function test_statistics_expose_pinned_execution_assumptions_charges_and_end_of_period_limitations(): void
    {
        [, , , , $run] = $this->seedOriginalRun();
        $run->forceFill([
            'execution_assumptions_json' => [
                'price_method' => 'ohlc_average',
                'adverse_slippage_percent' => 1.25,
                'charge_model' => ['version' => 'settings-sha256:test'],
            ],
        ])->save();

        $ctx = SimulationContext::blank(1000000, []);
        $ctx->set('total_fees', 12.34567);
        $ctx->set('pending_execution_drafts', [['action' => 'buy', 'symbol' => 'TEST']]);

        $statistics = app(StatisticsGenerator::class)->generate($run->fresh(), $ctx);

        $this->assertSame(12.3457, $statistics['simulated_charges']);
        $this->assertSame('ohlc_average', $statistics['execution_assumptions']['price_method']);
        $this->assertSame(1, $statistics['unresolved_end_recommendations']);
        $this->assertSame(
            ['recommendations_at_period_end_have_no_next_eligible_session_within_requested_period'],
            $statistics['limitations']
        );
    }

    public function test_duplicate_payload_creates_new_run_with_original_inputs_and_current_strategy(): void
    {
        [$user, $profile, $current, $stale, $original] = $this->seedOriginalRun();
        $this->stubFastEligibility();

        $current->strategy->forceFill(['name' => 'Current Live Strategy'])->save();

        $originalTradeIds = BacktestTrade::query()->where('backtest_run_id', $original->id)->pluck('id')->all();
        $originalSnapshotIds = BacktestSnapshot::query()->where('backtest_run_id', $original->id)->pluck('id')->all();
        $originalTxIds = BacktestTransaction::query()->where('backtest_run_id', $original->id)->pluck('id')->all();
        $originalStats = $original->statistics_json;

        $payload = [
            'from_date' => '2024-01-02',
            'to_date' => '2024-01-03',
            'range_key' => '6m',
            'initial_capital' => 2500000,
            'notes' => 'keep these notes',
            'tags' => ['swing', 'v4'],
            'session_token' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'price_method' => 'ohlc_average',
            'adverse_slippage_percent' => 1.25,
        ];

        $created = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/backtests', $payload)
            ->assertOk()
            ->json('data.run');

        $this->assertNotNull($created['id']);
        $this->assertNotSame($original->id, $created['id']);
        $this->assertSame('2024-01-02', $created['from_date']);
        $this->assertSame('2024-01-03', $created['to_date']);
        $this->assertSame('6m', $created['range_key']);
        $this->assertEqualsWithDelta(2500000.0, (float) $created['initial_capital'], 0.0001);
        $this->assertSame('keep these notes', $created['notes']);
        $this->assertSame(['swing', 'v4'], $created['tags']);
        $this->assertSame($current->id, $created['strategy_version_id']);
        $this->assertNotSame($stale->id, $created['strategy_version_id']);
        $this->assertSame('Current Live Strategy', $created['strategy_name']);
        $this->assertSame($current->strategy_id, $created['strategy_id']);
        $this->assertSame('ohlc_average', $created['execution_assumptions']['price_method']);
        $this->assertSame(1.25, $created['execution_assumptions']['adverse_slippage_percent']);
        $this->assertStringStartsWith('settings-sha256:', $created['execution_assumptions']['charge_model']['version']);
        $this->assertNotEmpty($created['execution_assumptions']['charge_model']['components']);
        $this->assertStringContainsString('Current Live Strategy', (string) $created['name']);
        $this->assertStringNotContainsString('Original Frozen Run', (string) $created['name']);

        $original->refresh();
        $this->assertSame('Original Frozen Run', $original->name);
        $this->assertSame($stale->id, $original->strategy_version_id);
        $this->assertSame('Stale Snapshot Strategy', $original->strategy_name);
        $this->assertSame($originalStats, $original->statistics_json);
        $this->assertEquals(
            $originalTradeIds,
            BacktestTrade::query()->where('backtest_run_id', $original->id)->pluck('id')->all()
        );
        $this->assertEquals(
            $originalSnapshotIds,
            BacktestSnapshot::query()->where('backtest_run_id', $original->id)->pluck('id')->all()
        );
        $this->assertEquals(
            $originalTxIds,
            BacktestTransaction::query()->where('backtest_run_id', $original->id)->pluck('id')->all()
        );

        $this->assertEmpty(array_intersect(
            $originalTradeIds,
            BacktestTrade::query()->where('backtest_run_id', $created['id'])->pluck('id')->all()
        ));
        $this->assertEmpty(array_intersect(
            $originalSnapshotIds,
            BacktestSnapshot::query()->where('backtest_run_id', $created['id'])->pluck('id')->all()
        ));
        $this->assertEmpty(array_intersect(
            $originalTxIds,
            BacktestTransaction::query()->where('backtest_run_id', $created['id'])->pluck('id')->all()
        ));
        $this->assertFalse(
            collect(BacktestTrade::query()->where('backtest_run_id', $created['id'])->pluck('symbol'))
                ->contains('COPYME')
        );
        $this->assertArrayNotHasKey('copied_marker', $created['statistics'] ?? []);
        $this->assertTrue(
            BacktestTrade::query()->where('backtest_run_id', $original->id)->where('symbol', 'COPYME')->exists()
        );
    }

    public function test_create_with_strategy_version_id_still_pins_that_version(): void
    {
        [$user, $profile, $current, $stale] = $this->seedOriginalRun();
        $this->stubFastEligibility();

        $created = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/backtests', [
                'from_date' => '2024-01-02',
                'to_date' => '2024-01-03',
                'initial_capital' => 1000000,
                'session_token' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
                'strategy_version_id' => $stale->id,
            ])
            ->assertOk()
            ->json('data.run');

        $this->assertSame($stale->id, $created['strategy_version_id']);
        $this->assertNotSame($current->id, $created['strategy_version_id']);
    }

    public function test_index_show_update_and_delete_remain_intact(): void
    {
        [$user, $profile, $current, $stale, $original] = $this->seedOriginalRun();
        $this->stubFastEligibility();

        $list = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/backtests')
            ->assertOk()
            ->json('data.runs');
        $this->assertCount(1, $list);
        $this->assertSame($original->id, $list[0]['id']);

        $detail = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/backtests/'.$original->id)
            ->assertOk()
            ->json('data');
        $this->assertSame($original->id, $detail['id']);
        $trades = $detail['trades'] ?? [];
        $this->assertNotEmpty($trades);
        $this->assertSame('COPYME', $trades[0]['symbol']);

        $updated = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->putJson('/api/v1/backtests/'.$original->id, [
                'notes' => 'metadata only',
                'tags' => ['edited'],
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame('metadata only', $updated['notes']);
        $this->assertSame(['edited'], $updated['tags']);
        $this->assertSame($stale->id, $original->fresh()->strategy_version_id);
        $this->assertTrue(
            BacktestTrade::query()->where('backtest_run_id', $original->id)->where('symbol', 'COPYME')->exists()
        );

        $created = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/backtests', [
                'from_date' => '2024-01-02',
                'to_date' => '2024-01-03',
                'initial_capital' => 1000000,
                'session_token' => 'cccccccc-dddd-4eee-8fff-000000000000',
            ])
            ->assertOk()
            ->json('data.run');

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->deleteJson('/api/v1/backtests/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('portfolio_backtest_runs', ['id' => $created['id']]);
        $this->assertDatabaseHas('portfolio_backtest_runs', ['id' => $original->id]);
        $this->assertTrue(
            BacktestTrade::query()->where('backtest_run_id', $original->id)->where('symbol', 'COPYME')->exists()
        );
        $this->assertSame($current->id, TradingStrategy::query()->find($current->strategy_id)?->active_version_id);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategyVersion, 3: TradingStrategyVersion, 4: BacktestRun, 5: Stock}
     */
    private function seedOriginalRun(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $current = app(StrategyConfigurationService::class)->ensureActive($profile);

        $stale = TradingStrategyVersion::query()->create([
            'strategy_id' => $current->strategy_id,
            'version' => 2,
            'version_label' => 'stale-snapshot',
            'config_json' => $current->config_json,
            'status' => TradingStrategyVersion::STATUS_SUPERSEDED,
            'change_notes' => 'frozen original version',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'COPYME',
            'exchange' => 'NSE',
            'name' => 'Copy Me Ltd',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $run = BacktestRun::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'strategy_id' => $current->strategy_id,
            'strategy_version_id' => $stale->id,
            'strategy_name' => 'Stale Snapshot Strategy',
            'strategy_version_number' => 2,
            'name' => 'Original Frozen Run',
            'notes' => 'keep these notes',
            'tags_json' => ['swing', 'v4'],
            'range_key' => '6m',
            'from_date' => '2024-01-02',
            'to_date' => '2024-01-03',
            'initial_capital' => 2500000,
            'status' => BacktestRun::STATUS_COMPLETED,
            'stage' => BacktestRun::STAGE_COMPLETED,
            'statistics_json' => [
                'return_pct' => 42.5,
                'copied_marker' => true,
                'total_trades' => 1,
            ],
            'completed_at' => now(),
        ]);

        BacktestTrade::query()->create([
            'backtest_run_id' => $run->id,
            'stock_id' => $stock->id,
            'symbol' => 'COPYME',
            'buy_date' => '2023-06-01',
            'sell_date' => '2023-09-01',
            'holding_days' => 90,
            'buy_price' => 100,
            'sell_price' => 142.5,
            'quantity' => 10,
            'profit_loss' => 425,
            'return_pct' => 42.5,
            'cagr' => 40,
            'exit_reason' => 'EXIT',
            'entry_score' => 90,
            'is_open' => false,
        ]);

        BacktestSnapshot::query()->create([
            'backtest_run_id' => $run->id,
            'snapshot_date' => '2024-01-03',
            'cash' => 100000,
            'invested_value' => 2400000,
            'portfolio_value' => 2500000,
            'realized_profit' => 425,
            'unrealized_profit' => 0,
            'drawdown_pct' => 1.5,
            'holdings_count' => 1,
        ]);

        BacktestTransaction::query()->create([
            'backtest_run_id' => $run->id,
            'trade_date' => '2023-06-01',
            'stock_id' => $stock->id,
            'symbol' => 'COPYME',
            'side' => 'BUY',
            'quantity' => 10,
            'price' => 100,
            'value' => 1000,
            'reason' => 'OPEN',
        ]);

        return [$user, $profile, $current, $stale, $run, $stock];
    }

    private function stubFastEligibility(): void
    {
        $this->mock(EligibilityPrecomputeService::class, function ($mock): void {
            $mock->shouldReceive('advance')->andReturnUsing(
                function (BacktestRun $run, SimulationContext $ctx, float $deadline): array {
                    $eligibility = is_array($ctx->get('eligibility')) ? $ctx->get('eligibility') : [];
                    $eligibility['phase'] = 'done';
                    $ctx->set('eligibility', $eligibility);

                    return ['done' => true, 'context' => $ctx];
                }
            );
            $mock->shouldReceive('entryHitsForDate')->andReturn([]);
            $mock->shouldReceive('exitHitsByScreenerForDate')->andReturn([]);
        });
    }
}
