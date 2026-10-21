<?php

namespace Tests\Feature\V8;

use App\Models\ScreenerVersion;
use App\Models\Stock;
use App\Models\StrategyScreener;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvalidEnabledStrategySaveAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_replacement_keeps_enabled_strategy_and_historical_pins_unchanged(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id);

        $createdScreener = $this->postJson('/api/screeners', [
            'name' => 'Atomicity Screener',
            'scope' => 'holdings',
            'definition_json' => $this->rocGreaterThanDefinition(15),
        ])->assertCreated()->json('data');
        $screenerId = (int) $createdScreener['id'];

        // The default factory Strategy is enabled. Adopt the new dependency through
        // the ordinary API save so the pinned Strategy/Screener versions are real.
        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $strategyId = (int) $active['id'];
        $strategy = TradingStrategy::query()->findOrFail($strategyId);
        $this->assertTrue($strategy->isEnabled(), 'The counterexample must start from an enabled Strategy.');
        $this->assertSame($strategy->active_version_id, (int) $active['version_id']);
        $this->assertSame(TradingStrategyVersion::STATUS_ACTIVE, $active['version_status']);

        $config = $active['config'];
        $config['eligibility_sources'] = [[
            'screener_id' => $screenerId,
            'screener_name' => 'Atomicity Screener',
            'enabled' => true,
            'priority' => 1,
            'display_order' => 0,
        ]];
        $saved = $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'config' => $config,
            'change_notes' => 'Pin the atomicity fixture Screener',
        ])->assertOk()->json('data');

        $strategy->refresh();
        $strategyVersionId = (int) $saved['version_id'];
        $strategyVersion = TradingStrategyVersion::query()->findOrFail($strategyVersionId);
        $dependency = StrategyScreener::query()
            ->where('strategy_version_id', $strategyVersionId)
            ->where('screener_id', $screenerId)
            ->firstOrFail();
        $screenerVersionId = (int) $dependency->screener_version_id;
        $this->assertTrue($strategy->isEnabled());
        $this->assertSame($strategyVersionId, (int) $strategy->active_version_id);
        $this->assertSame(TradingStrategyVersion::STATUS_ACTIVE, $strategyVersion->status);
        $this->assertGreaterThan(0, $screenerVersionId);
        $this->assertSame(1, (int) ScreenerVersion::query()->findOrFail($screenerVersionId)->version);

        // Like the existing FEAT-064 audit fixture, these historical records are
        // seeded directly; this is provenance evidence, not decision-pipeline proof.
        $stock = Stock::query()->create([
            'symbol' => 'INV001',
            'exchange' => 'NSE',
            'name' => 'Atomicity Fixture Company',
            'is_active' => true,
        ]);
        $recommendation = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategyVersionId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $transaction = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-09-15',
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $recommendation->id,
        ]);

        $this->assertSame($strategyVersionId, (int) $recommendation->strategy_version_id);
        $this->assertSame($recommendation->id, (int) $transaction->recommendation_id);
        $pinnedDetailBefore = $this->assertRecommendationResolvesPinnedDefinition(
            (int) $recommendation->id,
            $strategyVersionId,
            $screenerVersionId,
        );
        $before = $this->relevantPersistedState($strategyId, $screenerId, (int) $recommendation->id);

        $invalidConfig = $config;
        $invalidConfig['eligibility_sources'] = [];
        $invalidConfig['thresholds']['open_position'] = 42;
        $invalidConfig['indicators'] = array_map(
            static fn (array $indicator): array => array_merge($indicator, ['enabled' => false]),
            $invalidConfig['indicators'],
        );

        $invalidResponse = $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'name' => 'Must not replace the enabled Strategy',
            'description' => 'Must not leak from rejected replacement.',
            'config' => $invalidConfig,
            'change_notes' => 'Deliberately invalid all-disabled replacement',
        ]);
        $invalidResponse->assertUnprocessable()->assertJsonValidationErrors(['indicators']);
        $this->assertContains(
            'At least one enabled indicator must have a positive weight.',
            $invalidResponse->json('errors.indicators'),
        );

        $after = $this->relevantPersistedState($strategyId, $screenerId, (int) $recommendation->id);
        $this->assertSame($before, $after, 'Rejected replacement must leave all captured version, dependency, Screener, and history rows unchanged.');
        $strategy->refresh();
        $this->assertTrue($strategy->isEnabled());
        $this->assertSame($strategyVersionId, (int) $strategy->active_version_id);
        $this->assertSame($pinnedDetailBefore, $this->assertRecommendationResolvesPinnedDefinition(
            (int) $recommendation->id,
            $strategyVersionId,
            $screenerVersionId,
        ));
        $currentAfterReject = $this->getJson('/api/v1/strategy?strategy_id='.$strategyId)
            ->assertOk()
            ->assertJsonPath('data.id', $strategyId)
            ->assertJsonPath('data.version_id', $strategyVersionId)
            ->assertJsonPath('data.status', TradingStrategy::STATUS_ACTIVE)
            ->json('data');
        $this->assertSame($config['thresholds']['open_position'], $currentAfterReject['config']['thresholds']['open_position']);
        $this->assertSame($screenerId, (int) $currentAfterReject['config']['eligibility_sources'][0]['screener_id']);

        $transactionRows = $this->getJson('/api/v1/transactions')->assertOk()->json('data');
        $this->assertCount(1, array_filter($transactionRows, static fn (array $row): bool => (
            (int) ($row['id'] ?? 0) === (int) $transaction->id
            && (int) ($row['recommendation_id'] ?? 0) === (int) $recommendation->id
        )));
    }

    /** @return array<string, mixed> */
    private function assertRecommendationResolvesPinnedDefinition(
        int $recommendationId,
        int $strategyVersionId,
        int $screenerVersionId,
    ): array {
        $detail = $this->getJson('/api/v1/recommendations/'.$recommendationId)
            ->assertOk()
            ->assertJsonPath('data.strategy_version_id', $strategyVersionId)
            ->assertJsonPath('data.provenance.strategy_version_id', $strategyVersionId)
            ->json('data');

        $pinned = $detail['provenance']['pinned_screeners'][0] ?? [];
        $this->assertSame($screenerVersionId, (int) ($pinned['screener_version_id'] ?? 0));
        $this->assertSame(1, (int) ($pinned['semantic_version'] ?? 0));
        $this->assertSame(15.0, (float) ($pinned['definition_json']['root']['right']['value'] ?? -1));

        return [
            'strategy_version_id' => (int) $detail['provenance']['strategy_version_id'],
            'screener_version_id' => (int) $pinned['screener_version_id'],
            'semantic_version' => (int) $pinned['semantic_version'],
            'definition_json' => $pinned['definition_json'],
        ];
    }

    /** @return array<string, mixed> */
    private function relevantPersistedState(int $strategyId, int $screenerId, int $recommendationId): array
    {
        $versionIds = TradingStrategyVersion::query()
            ->where('strategy_id', $strategyId)
            ->orderBy('id')
            ->pluck('id');
        $recommendationIds = TradingRecommendation::query()
            ->whereIn('strategy_version_id', $versionIds)
            ->orderBy('id')
            ->pluck('id');

        return [
            'strategy' => (array) TradingStrategy::query()->findOrFail($strategyId)->getAttributes(),
            'strategy_versions' => $this->rows('portfolio_tos_strategy_versions', 'strategy_id', $strategyId),
            'strategy_screener_dependencies' => DB::table('portfolio_tos_strategy_screeners')
                ->whereIn('strategy_version_id', $versionIds)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
            'screener' => (array) DB::table('portfolio_screeners')->where('id', $screenerId)->first(),
            'screener_versions' => $this->rows('portfolio_screener_versions', 'screener_id', $screenerId),
            'recommendations' => DB::table('portfolio_tos_recommendations')
                ->whereIn('id', $recommendationIds)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
            'target_recommendation' => (array) DB::table('portfolio_tos_recommendations')->where('id', $recommendationId)->first(),
            'linked_transactions' => DB::table('portfolio_transactions')
                ->whereIn('recommendation_id', $recommendationIds)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, string $foreignKey, int $id): array
    {
        return DB::table($table)
            ->where($foreignKey, $id)
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /** @return array<string, mixed> */
    private function rocGreaterThanDefinition(float $threshold): array
    {
        return ['root' => [
            'type' => 'condition',
            'left' => ['indicator' => 'roc', 'params' => ['period' => 12]],
            'operator' => 'gt',
            'right' => ['type' => 'constant', 'value' => $threshold],
        ]];
    }
}
