<?php

namespace Tests\Feature;

use App\Models\AiAgentRun;
use App\Models\AiInsightCache;
use App\Models\AiPrompt;
use App\Models\AiProviderPath;
use App\Models\Holding;
use App\Models\ReusableArtifact;
use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\AI\EmbeddedAiContract;
use App\Services\AI\EmbeddedAiService;
use App\Services\AI\StockInsightEvidence;
use App\Services\AI\StrategyDesignerInput;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\Fundamentals\AI\FundamentalInsightReuse;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddedAiInsightTest extends TestCase
{
    use RefreshDatabase;

    private function response(string $cap = 'stock_analysis_insight'): array
    {
        $result = array_fill_keys(EmbeddedAiContract::schema($cap)['required'], 'Evidence is incomplete; review the supplied dates.');
        if ($cap === 'strategy_designer') {
            $result['draft_envelope'] = ['schema_version' => '1.0', 'artifact_type' => 'strategy', 'slug' => 'ai-draft', 'name' => 'AI Draft', 'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'], 'definition' => ['eligibility_sources' => [], 'scoring_model' => [['key' => 'relative_strength', 'enabled' => true, 'weight' => 100]]]];
        }

        return $result;
    }

    private function runtime(string $cap = 'stock_analysis_insight', ?array $response = null): void
    {
        Http::swap(new Factory);
        config(['ai_runtime.enabled' => true, 'ai_runtime.base_url' => 'http://ai.private']);
        Http::fake(['http://ai.private/*' => Http::response(['data' => ['status' => 'success', 'structured' => $response ?? $this->response($cap), 'prompt' => ['version' => 1], 'provider' => 'secret-provider', 'model' => 'secret-model']])]);
    }

    private function context(): array
    {
        return ['scope' => 'global_stock', 'stock_id' => 1, 'input' => ['stock' => ['id' => 1], 'data_limitations' => ['Fundamental interpretation unavailable']], 'data_as_of' => ['ohlcv_through' => '2026-10-01']];
    }

    public function test_cache_hits_refresh_failures_versions_and_provider_independence(): void
    {
        $this->runtime();
        $service = app(EmbeddedAiService::class);
        $context = $this->context();
        $first = $service->execute('stock_analysis_insight', $context, 1);
        self::assertSame('ready', $first['status']);
        self::assertStringContainsString('Fundamental interpretation unavailable', $first['response']['data_limitations']);
        AiProviderPath::query()->create(['path_id' => 'changed', 'provider' => 'new', 'model' => 'new', 'priority' => 1, 'enabled' => true]);
        self::assertEqualsCanonicalizing($first, $service->execute('stock_analysis_insight', $context, 2));
        Http::assertSentCount(1);
        $service->execute('stock_analysis_insight', $context, 1, true);
        Http::assertSentCount(2);
        Http::swap(new Factory);
        Http::fake(['http://ai.private/*' => Http::response(['data' => ['status' => 'failure', 'structured' => $this->response(), 'text' => 'partial stream']])]);
        $failed = $service->execute('stock_analysis_insight', $context, 1, true);
        self::assertSame('cached_degraded', $failed['status']);
        self::assertSame($first['response'], $failed['response']);
        self::assertSame(1, AiInsightCache::count());
        $context['input']['stock']['revised'] = true;
        self::assertSame('unavailable', $service->execute('stock_analysis_insight', $context, 1)['status']);
        self::assertSame(1, AiInsightCache::count());
        AiPrompt::where('prompt_id', 'stock_analysis_insight')->update(['version' => 2]);
        self::assertSame('missing', $service->execute('stock_analysis_insight', $this->context(), 1, false, true)['status']);
        self::assertNull(AiInsightCache::first()->user_id);
        self::assertNull(AiInsightCache::first()->profile_id);
        self::assertStringNotContainsString('secret-provider', json_encode($first));
    }

    public function test_invalid_schema_never_replaces_cache_and_prescriptive_output_is_rejected(): void
    {
        $this->runtime(response: ['summary' => 'partial']);
        self::assertSame('unavailable', app(EmbeddedAiService::class)->execute('stock_analysis_insight', $this->context(), 1)['status']);
        $this->runtime(response: array_replace($this->response(), ['summary' => 'You should buy this stock.']));
        self::assertSame('unavailable', app(EmbeddedAiService::class)->execute('stock_analysis_insight', $this->context(), 1)['status']);
        self::assertSame(0, AiInsightCache::count());
    }

    public function test_global_cache_rejects_private_context(): void
    {
        $this->expectException(\LogicException::class);
        app(EmbeddedAiService::class)->execute('stock_analysis_insight', $this->context() + ['user_id' => 1], 1);
    }

    public function test_stock_evidence_is_global_for_watchlist_and_personal_only_for_active_holding(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user);
        $other = $this->createPortfolioProfile($user, 'Other', false);
        $stock = Stock::create(['symbol' => 'AI003', 'exchange' => 'NSE', 'name' => 'AI stock', 'is_active' => true]);
        $watchlist = Watchlist::where('profile_id', $profile->id)->first();
        $watchlist->items()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'note' => 'PRIVATE WATCHLIST SECRET']);
        Holding::create(['profile_id' => $other->id, 'stock_id' => $stock->id, 'quantity' => 5, 'avg_buy_price' => 100, 'invested_amount' => 500]);
        $assembler = app(StockInsightEvidence::class);
        $global = $assembler->assemble($stock, $profile, $user);
        self::assertSame('global_stock', $global['scope']);
        self::assertNull($global['user_id']);
        self::assertArrayNotHasKey('holding', $global['input']);
        self::assertStringNotContainsString('PRIVATE WATCHLIST SECRET', json_encode($global));
        self::assertNotEmpty($global['input']['data_limitations']);
        $personal = $assembler->assemble($stock, $other, $user);
        self::assertSame('account_holding', $personal['scope']);
        self::assertSame($other->id, $personal['profile_id']);
        self::assertTrue($personal['data_as_of']['holding_personalized']);
        self::assertNull($personal['input']['holding'][0]['allocation_market_percent']);
    }

    public function test_reuse_requires_matching_deterministic_evidence_and_never_generates(): void
    {
        $reuse = app(FundamentalInsightReuse::class);
        $reuse->remember(1, ['signal' => 1], ['ai' => ['status' => 'ok'], 'ai_interpretation' => ['summary' => 'Existing interpretation']]);
        self::assertSame(['summary' => 'Existing interpretation'], $reuse->current(1, ['signal' => 1]));
        self::assertNull($reuse->current(1, ['signal' => 2]));
        $reuse->remember(1, ['signal' => 1], ['ai' => ['status' => 'unavailable']]);
        self::assertNotNull($reuse->current(1, ['signal' => 1]));
    }

    public function test_strategy_normalization_account_isolation_and_no_automatic_mutation(): void
    {
        $this->runtime('strategy_designer');
        $user = User::factory()->create();
        $this->createPortfolioProfile($user);
        $input = ['investmentStyle' => 'Momentum Investing', 'riskProfile' => 'Medium', 'holdingPeriod' => '1–4 Weeks', 'targetMarket' => 'NSE', 'universe' => 'All Equities', 'maximumPositions' => 5, 'capitalAllocation' => 'Equal Weight', 'preferredExitStyle' => 'ATR Stop', 'marketPreferences' => ['Bull Markets', 'Low Volatility'], 'optimizationPriorities' => [], 'strategyComplexity' => 'Simple', 'explainabilityLevel' => 'Standard'];
        $first = $this->actingAs($user)->postJson('/api/ai/insights/strategy', ['inputs' => $input])->assertOk()->json('data');
        self::assertSame('ready', $first['status']);
        $input['marketPreferences'] = ['Low Volatility', 'Bull Markets', 'Bull Markets'];
        $input['maximumPositions'] = '5';
        $input['customUniverse'] = 'ignored hidden field';
        self::assertSame($first['fingerprint'], $this->postJson('/api/ai/insights/strategy', ['inputs' => $input, 'lookup_only' => true])->json('data.fingerprint'));
        Http::assertSentCount(1);
        $input['maximumPositions'] = 6;
        self::assertSame('missing', $this->postJson('/api/ai/insights/strategy', ['inputs' => $input, 'lookup_only' => true])->json('data.status'));
        self::assertSame(0, AiAgentRun::count());
        $other = User::factory()->create();
        $this->createPortfolioProfile($other);
        $this->actingAs($other)->postJson('/api/ai/insights/strategy/draft', ['fingerprint' => $first['fingerprint']])->assertNotFound();
        $this->postJson('/api/ai/insights/strategy', ['inputs' => $input])->assertOk();
        self::assertSame(2, AiInsightCache::where('scope', 'account')->count());
    }

    public function test_authentication_and_cross_profile_access_are_authoritative(): void
    {
        $stock = Stock::create(['symbol' => 'AI003', 'exchange' => 'NSE', 'name' => 'AI stock', 'is_active' => true]);
        $this->postJson('/api/ai/insights/stocks/'.$stock->id)->assertUnauthorized();
        $other = User::factory()->create();
        $profile = $this->createPortfolioProfile($other);
        $this->actingAs(User::factory()->create())->withHeader('X-Profile-Id', $profile->id)->postJson('/api/ai/insights/stocks/'.$stock->id)->assertNotFound();
    }

    public function test_strategy_draft_uses_existing_preview_approval_and_verified_library_lifecycle(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user);
        $response = $this->response('strategy_designer');
        $this->runtime('strategy_designer', $response);
        $result = app(EmbeddedAiService::class)->execute('strategy_designer', ['scope' => 'account', 'user_id' => $user->id, 'input' => ['style' => 'Momentum']], $user->id);
        self::assertSame('ready', $result['status']);
        $before = ReusableArtifact::count();
        $previewResponse = $this->actingAs($user)->withHeader('X-Profile-Id', $profile->id)->postJson('/api/ai/insights/strategy/draft', ['fingerprint' => $result['fingerprint']]);
        self::assertSame(200, $previewResponse->status(), $previewResponse->getContent());
        $plan = $previewResponse->json('data');
        self::assertSame('awaiting_approval', $plan['status']);
        self::assertSame('strategy.create', $plan['plan'][0]['tool']);
        self::assertSame($before, ReusableArtifact::count());
        $this->postJson('/api/ai/assistant/runs/'.$plan['id'].'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.steps.0.status', 'verified');
        self::assertSame($before + 1, ReusableArtifact::count());
        $this->postJson('/api/ai/assistant/runs/'.$plan['id'].'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk();
        self::assertSame($before + 1, ReusableArtifact::count());
        self::assertSame('draft', ReusableArtifact::latest('id')->first()->versions()->first()->status);
    }

    public function test_large_strategy_preview_preserves_the_existing_ai002_safety_limit(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $envelope = app(StrategyArtifactRegistry::class)->get((string) $strategy->id, $profile);
        $envelope = array_intersect_key($envelope, array_flip(['schema_version', 'artifact_type', 'slug', 'name', 'metadata', 'definition']));
        $envelope['slug'] = 'ai003-reviewed-draft';
        $envelope['name'] = 'Reviewed AI design';
        $envelope['metadata'] = ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'];
        $response = $this->response('strategy_designer');
        $response['draft_envelope'] = $envelope;
        $this->runtime('strategy_designer', $response);
        $result = app(EmbeddedAiService::class)->execute('strategy_designer', ['scope' => 'account', 'user_id' => $user->id, 'input' => ['style' => 'Momentum']], $user->id);
        self::assertSame('ready', $result['status']);
        $before = ReusableArtifact::count();
        $previewResponse = $this->actingAs($user)->withHeader('X-Profile-Id', $profile->id)->postJson('/api/ai/insights/strategy/draft', ['fingerprint' => $result['fingerprint']]);
        $previewResponse->assertUnprocessable()->assertJsonPath('error.code', 'preview_too_large');
        self::assertSame($before, ReusableArtifact::count());
        self::assertSame(0, AiAgentRun::count());
    }

    public function test_holding_cache_never_reuses_another_account_or_portfolio(): void
    {
        $this->runtime();
        $service = app(EmbeddedAiService::class);
        $context = array_replace($this->context(), ['scope' => 'account_holding', 'user_id' => 10, 'profile_id' => 20]);
        $first = $service->execute('stock_analysis_insight', $context, 10);
        foreach ([['user_id' => 11], ['profile_id' => 21], ['scope' => 'global_stock', 'user_id' => null, 'profile_id' => null]] as $change) {
            $lookup = $service->execute('stock_analysis_insight', array_replace($context, $change), 11, false, true);
            self::assertSame('missing', $lookup['status']);
            self::assertNotSame($first['fingerprint'], $lookup['fingerprint']);
        }
    }

    public function test_stock_contract_rejects_trading_instructions(): void
    {
        foreach (['Buy TCS now.', 'Rating: sell.', 'Consider adding your position.', 'You should reduce the position.', 'Target price 500.'] as $instruction) {
            try {
                EmbeddedAiContract::validate('stock_analysis_insight', array_replace($this->response(), ['summary' => $instruction]));
                self::fail('Trading instruction was accepted: '.$instruction);
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Prescriptive output', $error->getMessage());
            }
        }
    }

    public function test_generated_guide_timestamp_does_not_invalidate_strategy_input(): void
    {
        $guide = file_get_contents(base_path('../docs/current/stox-trading-artifacts-ai-guide.md'));
        $rebuilt = preg_replace('/> \*\*Generated:\*\* .*/', '> **Generated:** 2099-01-01T00:00:00Z', $guide);
        self::assertNotSame($guide, $rebuilt);
        self::assertSame(
            StrategyDesignerInput::authoringEvidence($guide),
            StrategyDesignerInput::authoringEvidence($rebuilt)
        );
        self::assertSame(hash('sha256', StrategyDesignerInput::authoringEvidence($rebuilt)), app(EmbeddedAiService::class)->authoringContractVersion());
    }
}
