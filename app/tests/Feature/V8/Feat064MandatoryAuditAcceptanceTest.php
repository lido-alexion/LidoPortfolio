<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\ScreenerVersion;
use App\Models\Stock;
use App\Models\StrategyScreener;
use App\Models\Transaction;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEAT-064 §7 — frozen ROC gate audit: historical recommendation/transaction resolve pinned strategy + screener versions.
 */
class Feat064MandatoryAuditAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_recommendation_resolves_original_screener_version_after_rule_tightening(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $screenerPayload = $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'ROC Gate',
            'scope' => 'holdings',
            'definition_json' => $this->rocGreaterThanDefinition(15),
        ])->assertCreated()->json('data');
        $screenerId = (int) $screenerPayload['id'];

        $strategyPayload = $this->postJson('/api/v1/strategies', [
            'name' => 'ROC Strategy',
            'description' => 'Audit scenario',
        ])->assertCreated()->json('data');
        $strategyId = (int) $strategyPayload['id'];

        $editor = $this->getJson('/api/v1/strategy?strategy_id='.$strategyId)->assertOk()->json('data');
        $config = $editor['config'];
        $config['eligibility_sources'] = [[
            'screener_id' => $screenerId,
            'screener_name' => 'ROC Gate',
            'enabled' => true,
            'priority' => 1,
            'display_order' => 0,
        ]];

        $saved = $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'config' => $config,
            'change_notes' => 'Pin ROC Gate V1',
        ])->assertOk()->json('data');

        $strategyVersionV1Id = (int) $saved['version_id'];
        $screenerVersionV1Id = (int) StrategyScreener::query()
            ->where('strategy_version_id', $strategyVersionV1Id)
            ->value('screener_version_id');
        $this->assertGreaterThan(0, $screenerVersionV1Id);
        $this->assertSame(15.0, $this->rocThresholdFromVersion($screenerVersionV1Id));

        $this->postJson('/api/screeners/'.$screenerId.'/run')->assertOk();
        $run = ScreenerRun::query()->where('screener_id', $screenerId)->latest('id')->firstOrFail();
        $this->assertSame($screenerVersionV1Id, (int) $run->screener_version_id);

        $stock = Stock::query()->create([
            'symbol' => 'ROC'.random_int(100, 999),
            'exchange' => 'NSE',
            'name' => 'ROC Audit Co',
            'is_active' => true,
        ]);

        $recommendation = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategyVersionV1Id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
        ]);

        $transaction = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $recommendation->id,
        ]);

        $this->putJson('/api/screeners/'.$screenerId, [
            'name' => 'ROC Gate',
            'scope' => 'holdings',
            'definition_json' => $this->rocGreaterThanDefinition(20),
            'change_notes' => 'Tighten to ROC > 20',
        ])->assertOk();

        $screener = Screener::query()->findOrFail($screenerId);
        $this->assertSame(2, (int) $screener->artifact_version);
        $this->assertSame(20.0, $this->rocThresholdFromDefinition($screener->definition_json));
        $this->assertSame(15.0, $this->rocThresholdFromVersion($screenerVersionV1Id));

        $configV2 = $config;
        $indicators = $configV2['indicators'];
        $indicators[0]['weight'] = ((float) ($indicators[0]['weight'] ?? 50)) + 1.0;
        $indicators[1]['weight'] = max(0, ((float) ($indicators[1]['weight'] ?? 50)) - 1.0);
        $configV2['indicators'] = $indicators;

        $adopted = $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'config' => $configV2,
            'change_notes' => 'Adopt ROC Gate V2',
        ])->assertOk()->json('data');

        $strategyVersionV2Id = (int) $adopted['version_id'];
        $this->assertNotSame($strategyVersionV1Id, $strategyVersionV2Id);
        $screenerVersionV2Id = (int) StrategyScreener::query()
            ->where('strategy_version_id', $strategyVersionV2Id)
            ->value('screener_version_id');
        $this->assertNotSame($screenerVersionV1Id, $screenerVersionV2Id);
        $this->assertSame(20.0, $this->rocThresholdFromVersion($screenerVersionV2Id));

        $recommendation->refresh();
        $this->assertSame($strategyVersionV1Id, (int) $recommendation->strategy_version_id);
        $pinnedStrategy = TradingStrategyVersion::query()->with('strategy')->findOrFail($strategyVersionV1Id);
        $pinnedLink = StrategyScreener::query()
            ->where('strategy_version_id', $pinnedStrategy->id)
            ->firstOrFail();
        $this->assertSame($screenerVersionV1Id, (int) $pinnedLink->screener_version_id);
        $this->assertSame(15.0, $this->rocThresholdFromVersion((int) $pinnedLink->screener_version_id));

        $transaction->refresh();
        $this->assertSame($recommendation->id, (int) $transaction->recommendation_id);

        $detail = $this->getJson('/api/v1/recommendations/'.$recommendation->id)
            ->assertOk()
            ->assertJsonPath('data.strategy_version_id', $strategyVersionV1Id)
            ->assertJsonPath('data.provenance.strategy_version_id', $strategyVersionV1Id)
            ->json('data');

        $pinned = $detail['provenance']['pinned_screeners'][0] ?? [];
        $this->assertSame($screenerVersionV1Id, (int) ($pinned['screener_version_id'] ?? 0));
        $this->assertSame(1, (int) ($pinned['semantic_version'] ?? 0));
        $this->assertSame(
            15.0,
            (float) ($pinned['definition_json']['root']['right']['value'] ?? -1)
        );
    }

    public function test_watchlist_change_versions_without_mutating_prior_screener_version(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $lists = $this->getJson('/api/watchlists')->assertOk()->json('data');
        $watchlistA = (int) $lists[0]['id'];
        $watchlistB = (int) $this->postJson('/api/watchlists', ['name' => 'Alt universe'])
            ->assertCreated()
            ->json('data.id');

        $definition = $this->rocGreaterThanDefinition(15);
        $created = $this->postJson('/api/screeners', [
            'name' => 'Watchlist Gate',
            'scope' => 'watchlist',
            'watchlist_id' => $watchlistA,
            'definition_json' => $definition,
        ])->assertCreated()->json('data');
        $screenerId = (int) $created['id'];
        $versionV1Id = (int) ScreenerVersion::query()
            ->where('screener_id', $screenerId)
            ->where('version', 1)
            ->value('id');

        $this->putJson('/api/screeners/'.$screenerId, [
            'name' => 'Watchlist Gate',
            'scope' => 'watchlist',
            'watchlist_id' => $watchlistB,
            'definition_json' => $definition,
            'change_notes' => 'Point at different watchlist',
        ])->assertOk();

        $versionV1 = ScreenerVersion::query()->findOrFail($versionV1Id);
        $this->assertSame($watchlistA, (int) $versionV1->watchlist_id);
        $versionV2 = ScreenerVersion::query()
            ->where('screener_id', $screenerId)
            ->where('version', 2)
            ->firstOrFail();
        $this->assertSame($watchlistB, (int) $versionV2->watchlist_id);
    }

    public function test_index_symbol_change_versions_without_mutating_prior_screener_version(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $definition = $this->rocGreaterThanDefinition(15);
        $created = $this->postJson('/api/screeners', [
            'name' => 'Index Gate',
            'scope' => 'index',
            'index_symbol' => 'NIFTYBANK',
            'definition_json' => $definition,
        ])->assertCreated()->json('data');
        $screenerId = (int) $created['id'];
        $versionV1Id = (int) ScreenerVersion::query()
            ->where('screener_id', $screenerId)
            ->where('version', 1)
            ->value('id');

        $this->putJson('/api/screeners/'.$screenerId, [
            'name' => 'Index Gate',
            'scope' => 'index',
            'index_symbol' => 'NIFTY50',
            'definition_json' => $definition,
            'change_notes' => 'Switch index universe',
        ])->assertOk();

        $versionV1 = ScreenerVersion::query()->findOrFail($versionV1Id);
        $this->assertSame('NIFTYBANK', strtoupper((string) $versionV1->index_symbol));
        $versionV2 = ScreenerVersion::query()
            ->where('screener_id', $screenerId)
            ->where('version', 2)
            ->firstOrFail();
        $this->assertSame('NIFTY50', strtoupper((string) $versionV2->index_symbol));
    }

    public function test_universe_change_versions_without_mutating_prior_screener_version(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $definition = $this->rocGreaterThanDefinition(15);
        $created = $this->postJson('/api/screeners', [
            'name' => 'Universe Gate',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated()->json('data');
        $screenerId = (int) $created['id'];
        $versionV1Id = (int) ScreenerVersion::query()
            ->where('screener_id', $screenerId)
            ->where('version', 1)
            ->value('id');

        $this->putJson('/api/screeners/'.$screenerId, [
            'name' => 'Universe Gate',
            'scope' => 'all_equities',
            'definition_json' => $definition,
            'change_notes' => 'Expand universe',
        ])->assertOk();

        $versionV1 = ScreenerVersion::query()->findOrFail($versionV1Id);
        $this->assertSame('holdings', $versionV1->scope);
        $versionV2 = ScreenerVersion::query()
            ->where('screener_id', $screenerId)
            ->where('version', 2)
            ->firstOrFail();
        $this->assertSame('all_equities', $versionV2->scope);
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

    private function rocThresholdFromVersion(int $screenerVersionId): float
    {
        $version = ScreenerVersion::query()->findOrFail($screenerVersionId);

        return $this->rocThresholdFromDefinition($version->definition_json ?? []);
    }

    /** @param  array<string, mixed>|null  $definition */
    private function rocThresholdFromDefinition(?array $definition): float
    {
        $root = is_array($definition) ? ($definition['root'] ?? $definition) : [];
        if (! is_array($root)) {
            return -1.0;
        }

        return (float) ($root['right']['value'] ?? -1);
    }
}
