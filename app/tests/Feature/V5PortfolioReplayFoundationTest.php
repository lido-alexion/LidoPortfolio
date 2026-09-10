<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBindingRevision;
use App\Models\Holding;
use App\Models\PortfolioReplayCheckpoint;
use App\Models\PortfolioReplayRun;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FeeCalculatorService;
use App\Services\Simulation\PortfolioReplayProcessor;
use App\Services\Simulation\PortfolioReplayService;
use App\Services\Simulation\ReplayStrategyEvaluator;
use App\Services\Simulation\ReplayTradeTransition;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V5PortfolioReplayFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_evaluates_pinned_strategy_and_screener_definitions_into_next_session_recommendation(): void
    {
        $stock = Stock::query()->create(['symbol' => 'PINNED', 'exchange' => 'NSE', 'name' => 'Pinned Candidate']);
        $date = Carbon::parse('2026-01-01');
        for ($day = 0; $day < 91; $day++) {
            $price = 100 + $day;
            StockPrice::query()->create([
                'stock_id' => $stock->id, 'price_date' => $date->copy()->addDays($day)->toDateString(),
                'open_price' => $price - 1, 'high_price' => $price + 1, 'low_price' => $price - 2,
                'close_price' => $price, 'volume' => 100000 + $day,
                'data_source' => 'test', 'created_at' => $date->copy()->addDays($day)->endOfDay(),
            ]);
        }
        $session = '2026-04-01';
        $state = [
            'holdings' => [], 'pending_recommendations' => [],
            'strategies' => [['strategy_id' => 7, 'available_capital' => 100000]],
        ];
        $world = ['binding_revisions' => [[
            'strategy_id' => 7, 'artifact_version_id' => 70, 'definition_hash' => 'pinned-hash',
            'strategy_definition' => [
                'scoring_model' => [['key' => 'trend_score', 'weight' => 100, 'enabled' => true]],
                'thresholds' => ['open_position' => 1],
                'portfolio_rules' => ['default_position_size_pct' => 10],
            ],
            'dependencies' => [[
                'artifact_type' => 'screener',
                'definition' => ['root' => [
                    'type' => 'condition', 'left' => ['indicator' => 'close'],
                    'operator' => 'gt', 'right' => ['type' => 'constant', 'value' => 0],
                ]],
            ]],
        ]]];

        $result = app(ReplayStrategyEvaluator::class)->evaluate($state, $world, $session);

        $this->assertSame(1, $result['generated']);
        $this->assertSame([], $result['limitations']);
        $recommendation = $result['state']['pending_recommendations'][0];
        $this->assertSame('buy', $recommendation['side']);
        $this->assertSame('2026-04-02', $recommendation['first_eligible_session']);
        $this->assertSame(70, $recommendation['evidence']['artifact_version_id']);
        $this->assertSame('pinned-hash', $recommendation['evidence']['definition_hash']);
        $this->assertGreaterThan(0, $recommendation['target_amount']);
    }

    public function test_replay_marks_completed_after_final_in_range_session(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $state = [
            'schema_version' => 1, 'cash_balance' => 1000, 'holdings' => [],
            'strategies' => [], 'pending_recommendations' => [], 'transactions' => [],
        ];
        $run = PortfolioReplayRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'profile_id' => $profile->id,
            'starting_mode' => 'new_simulated', 'period_start' => '2026-01-02', 'period_end' => '2026-01-02',
            'starting_cash' => 1000, 'price_method' => 'next_open', 'adverse_slippage_percent' => 0,
            'status' => 'queued', 'pinned_world' => ['binding_revisions' => [], 'portfolio_economic_settings' => []],
            'starting_state' => $state, 'readiness' => ['status' => 'ready'],
        ]);

        $result = app(PortfolioReplayProcessor::class)->process($run, 5);

        $this->assertSame('completed', $result['status']);
        $this->assertFalse($result['remaining']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('completed', $run->fresh()->results['processing_state']);
        $this->assertNotNull($run->fresh()->completed_at);
    }

    public function test_ready_scenario_pins_world_and_cannot_be_modified_after_queueing(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $artifact = ReusableArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(), 'owner_user_id' => $user->id,
            'artifact_type' => 'strategy', 'slug' => 'replay-strategy', 'name' => 'Replay Strategy', 'origin' => 'authored',
        ]);
        $version = ReusableArtifactVersion::query()->create([
            'artifact_id' => $artifact->id, 'semver' => '1.0.0', 'status' => 'published',
            'content_json' => ['definition' => ['scoring_model' => [['key' => 'trend_score', 'weight' => 100, 'enabled' => true]]]],
            'definition_hash' => hash('sha256', 'replay'),
            'created_by_user_id' => $user->id, 'published_at' => now(),
        ]);
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Replay Strategy',
            'slug' => 'replay-strategy',
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 100,
            'reusable_artifact_id' => $artifact->id,
        ]);
        $binding = ArtifactBinding::query()->create([
            'binding_uuid' => (string) Str::uuid(), 'profile_id' => $profile->id,
            'artifact_id' => $artifact->id, 'status' => 'enabled', 'usability_state' => 'usable',
        ]);
        $revision = ArtifactBindingRevision::query()->create([
            'binding_id' => $binding->id, 'revision_number' => 1, 'artifact_version_id' => $version->id,
            'binding_status' => 'enabled', 'usability_state' => 'usable', 'action' => 'created',
            'activated_by_user_id' => $user->id, 'activated_at' => now(),
        ]);
        $binding->forceFill(['active_revision_id' => $revision->id])->save();

        $payload = [
            'starting_mode' => 'new_simulated', 'period_start' => '2026-01-02', 'period_end' => '2026-01-30',
            'starting_cash' => 100000, 'price_method' => 'next_open', 'adverse_slippage_percent' => 0.5,
        ];
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/replays/readiness', $payload)->assertOk()->assertJsonPath('data.status', 'ready');
        $created = $this->postJson('/api/replays', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.pinned_world.binding_revisions.0.artifact_version_id', $version->id)
            ->assertJsonPath('data.pinned_world.binding_revisions.0.definition_hash', $version->definition_hash)
            ->assertJsonPath('data.pinned_world.binding_revisions.0.strategy_definition.scoring_model.0.key', 'trend_score')
            ->assertJsonPath('data.pinned_world.binding_revisions.0.strategy_id', $strategy->id)
            ->assertJsonPath('data.starting_state.schema_version', 1)
            ->assertJsonPath('data.starting_state.strategies.0.allocation_pct', 100);
        $id = $created->json('data.id');
        $this->putJson('/api/replays/'.$id, ['starting_cash' => 1])->assertMethodNotAllowed();
        $run = PortfolioReplayRun::query()->findOrFail($id);
        $this->assertStringStartsWith('settings-sha256:', $run->pinned_world['charge_model']['version']);
        $this->assertNotEmpty($run->pinned_world['charge_model']['components']);
        $this->assertSame('india_equity', $run->pinned_world['calendar']['market']);
        $this->assertSame('daily_eod', $run->pinned_world['calendar']['resolution']);
        $this->assertArrayHasKey('portfolio_cash_reserve_pct', $run->pinned_world['portfolio_economic_settings']);
        $this->assertSame([], $run->starting_state['loans']);
        $this->assertSame([], $run->starting_state['recall_bridge_loans']);
        $sourceEconomicState = [
            'holdings' => Holding::query()->where('profile_id', $profile->id)->count(),
            'transactions' => Transaction::query()->where('profile_id', $profile->id)->count(),
            'cash_ledger' => DB::table('portfolio_cash_ledger_entries')->where('profile_id', $profile->id)->count(),
        ];
        $slice = app(PortfolioReplayProcessor::class)->process($run, 2);
        $this->assertSame('running', $slice['status']);
        $this->assertSame(2, $slice['processed_sessions']);
        $this->assertSame('2026-01-05', $slice['checkpoint_date']);
        app(PortfolioReplayProcessor::class)->process($run->fresh(), 1);
        $this->assertSame(3, PortfolioReplayCheckpoint::query()->where('replay_run_id', $id)->count());
        $checkpoint = PortfolioReplayCheckpoint::query()->where('replay_run_id', $id)->latest('id')->firstOrFail();
        $this->assertNotEmpty($checkpoint->market_evidence['sha256']);
        $this->assertSame('economic_checkpoint', $checkpoint->stage);
        $this->assertEquals(100000.0, $checkpoint->state_after['valuation']['total_value']);
        $this->assertEquals(100000.0, $checkpoint->state_after['capital']['investable_capital']);
        $this->assertEquals(100000.0, $checkpoint->state_after['strategies'][0]['available_capital']);
        $this->assertContains('pinned_strategy_eligibility_missing', $checkpoint->limitations);
        $this->getJson('/api/replays/'.$id)->assertOk()
            ->assertJsonPath('data.evidence_summary.checkpoint_count', 3)
            ->assertJsonPath('data.checkpoints.2.stage', 'economic_checkpoint')
            ->assertJsonPath('data.checkpoints.2.market_evidence.sha256', $checkpoint->market_evidence['sha256'])
            ->assertJsonStructure(['data' => ['pinned_world', 'starting_state', 'checkpoints', 'evidence_summary']]);
        $this->assertSame($sourceEconomicState, [
            'holdings' => Holding::query()->where('profile_id', $profile->id)->count(),
            'transactions' => Transaction::query()->where('profile_id', $profile->id)->count(),
            'cash_ledger' => DB::table('portfolio_cash_ledger_entries')->where('profile_id', $profile->id)->count(),
        ], 'Replay processing must never mutate the source Portfolio economic state.');
        $this->postJson('/api/replays/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, app(PortfolioReplayProcessor::class)->process($run->fresh(), 5)['processed_sessions']);
        $this->deleteJson('/api/replays/'.$id)->assertOk();
        $this->getJson('/api/replays/'.$id)->assertNotFound();
        $this->assertDatabaseHas('portfolio_replay_run_tombstones', [
            'run_uuid' => $created->json('data.run_uuid'), 'final_status' => 'cancelled',
            'deleted_by_user_id' => $user->id,
        ]);

        $strategy->delete();
        $unprojectable = app(PortfolioReplayService::class)->readiness($profile, $payload);
        $this->assertSame('blocked', $unprojectable['status']);
        $this->assertContains('strategy_projection_missing', $unprojectable['limitations']);
        $this->assertContains('strategy_allocations_not_complete', $unprojectable['limitations']);
    }

    public function test_missing_strategy_world_blocks_instead_of_silently_shortening(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/replays', [
                'starting_mode' => 'new_simulated', 'period_start' => '2026-01-02', 'period_end' => '2026-01-30',
                'starting_cash' => 100000, 'price_method' => 'next_open',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.readiness.0', 'no_enabled_strategy_artifact_bindings');
    }

    public function test_historical_branch_blocks_when_strategy_capital_state_is_not_reconstructable(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $readiness = app(PortfolioReplayService::class)->readiness($profile, [
            'starting_mode' => 'historical_branch',
            'period_start' => '2026-01-02',
            'period_end' => '2026-01-30',
            'price_method' => 'next_open',
        ]);

        $this->assertSame('blocked', $readiness['status']);
        $this->assertContains(
            'historical_strategy_capital_state_not_reconstructable',
            $readiness['limitations'],
        );
    }

    public function test_replay_trade_transition_uses_pinned_price_charges_and_is_idempotent(): void
    {
        $stock = Stock::query()->create(['symbol' => 'REPLAY', 'exchange' => 'NSE', 'name' => 'Replay Fill']);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-02',
            'open_price' => 100, 'high_price' => 110, 'low_price' => 95, 'close_price' => 105,
            'data_source' => 'test', 'created_at' => '2026-01-02 18:00:00',
        ]);
        $components = app(FeeCalculatorService::class)->componentsFromSettings();
        $state = [
            'cash_balance' => 1000, 'holdings' => [], 'transactions' => [],
            'pending_recommendations' => [[
                'key' => 'rec-1', 'stock_id' => $stock->id, 'strategy_id' => 7,
                'side' => 'buy', 'quantity' => 4, 'exchange' => 'NSE',
                'first_eligible_session' => '2026-01-02', 'status' => 'pending',
            ]],
        ];
        $assumptions = [
            'price_method' => 'next_open', 'adverse_slippage_percent' => 1,
            'charge_model' => ['version' => 'test-v1', 'components' => $components],
        ];

        $first = app(ReplayTradeTransition::class)->apply($state, '2026-01-02', $assumptions);
        $this->assertFalse($first['waiting']);
        $this->assertSame(1, $first['fills']);
        $this->assertSame(101.0, $first['state']['transactions'][0]['price']);
        $this->assertSame('test-v1', $first['state']['transactions'][0]['evidence']['charge_model_version']);
        $this->assertSame(4.0, $first['state']['holdings'][0]['quantity']);
        $this->assertLessThan(596.0, $first['state']['cash_balance']);
        $second = app(ReplayTradeTransition::class)->apply($first['state'], '2026-01-02', $assumptions);
        $this->assertSame(0, $second['fills']);
        $this->assertCount(1, $second['state']['transactions']);

        $missing = $state;
        $missing['pending_recommendations'][0]['stock_id'] = $stock->id + 999;
        $waiting = app(ReplayTradeTransition::class)->apply($missing, '2026-01-02', $assumptions);
        $this->assertTrue($waiting['waiting']);
        $this->assertContains('missing_session_ohlc', $waiting['limitations']);
        $this->assertSame($missing, $waiting['state']);
    }
}
