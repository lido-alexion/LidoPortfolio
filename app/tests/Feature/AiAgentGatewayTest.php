<?php

namespace Tests\Feature;

use App\Models\{AiAgentRun, User, Watchlist};
use App\Services\AI\{AiAgentService, AiToolCatalog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AiAgentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function runFixture(?User $user = null, mixed $token = null): array
    {
        $user ??= User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        if ($token) $user->withAccessToken($token);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->headers->set('X-Profile-Id', $profile->id);
        [$run, $delegation] = app(AiAgentService::class)->create($request, 'Create a watchlist');
        config(['ai_runtime.shared_secret' => 'private-test']);
        return [$user, $profile, $run, ['run_id' => $run->id, 'delegation' => $delegation]];
    }
    private function toolCall(array $envelope, array $input)
    {
        return $this->withHeader('X-StoX-AI-Service-Key', 'private-test')->postJson('/api/internal/v1/ai-tools/call', $envelope + $input);
    }
    private function preview(array $envelope, array $plan = [])
    {
        return $this->toolCall($envelope, ['operation' => 'preview', 'plan' => $plan ?: [['tool' => 'watchlist.create', 'arguments' => ['name' => 'AI List'], 'reason' => 'Create the requested list']]])->assertOk()->json('data');
    }

    public function test_run_history_and_missing_profile_never_initialize_business_state(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/ai/assistant/runs')->assertOk();
        $this->postJson('/api/ai/assistant/runs', ['objective' => 'Read holdings'])->assertConflict()->assertJsonPath('error.code', 'not_initialized');
        self::assertSame(0, $user->portfolios()->count());
        self::assertSame(0, Watchlist::query()->count());
    }

    public function test_private_auth_unknown_tools_malformed_inputs_and_no_broker_tools(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $this->postJson('/api/internal/v1/ai-tools/call', $envelope + ['operation' => 'catalog'])->assertUnauthorized();
        $catalog = $this->toolCall($envelope, ['operation' => 'catalog'])->assertOk()->json('data.tools');
        foreach ($catalog as $tool) self::assertStringNotContainsString('broker', $tool['id']);
        $this->toolCall($envelope, ['operation' => 'read', 'tool' => 'broker.place', 'arguments' => []])->assertUnprocessable()->assertJsonPath('error.code', 'tool_not_allowed');
        $this->toolCall($envelope, ['operation' => 'read', 'tool' => 'watchlist.items', 'arguments' => ['id' => 1, 'user_id' => 999]])->assertUnprocessable();
        $this->toolCall($envelope, ['operation' => 'read', 'tool' => 'watchlist.items', 'arguments' => ['id' => '1']])->assertUnprocessable();
        $this->toolCall(array_replace($envelope, ['delegation' => str_repeat('0', 64)]), ['operation' => 'catalog'])->assertForbidden();
    }

    public function test_cross_account_reads_history_and_approval_are_denied(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $other = User::factory()->create();
        $foreign = $this->defaultPortfolioFor($other);
        $watchlist = Watchlist::query()->create(['profile_id' => $foreign->id, 'name' => 'Private', 'is_default' => false, 'sort_order' => 1]);
        $this->toolCall($envelope, ['operation' => 'read', 'tool' => 'watchlist.items', 'arguments' => ['id' => $watchlist->id]])->assertNotFound();
        $plan = $this->preview($envelope);
        $this->actingAs($other)->getJson('/api/ai/assistant/runs/'.$run->id)->assertNotFound();
        $this->actingAs($other)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertNotFound();
    }

    public function test_delegated_token_scope_and_revocation_are_rechecked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('read only', ['portfolio:read'])->accessToken;
        [$user, $profile, $run, $envelope] = $this->runFixture($user, $token);
        $this->toolCall($envelope, ['operation' => 'preview', 'plan' => []])->assertForbidden();
        config(['sanctum.expiration' => 1]);
        $token->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $this->toolCall($envelope, ['operation' => 'catalog'])->assertForbidden();
        $token->delete();
        $this->toolCall($envelope, ['operation' => 'catalog'])->assertForbidden();
    }

    public function test_exact_approval_duplicate_execution_and_changed_hash(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $plan = $this->preview($envelope);
        $url = '/api/ai/assistant/runs/'.$run->id.'/approve';
        $this->actingAs($user)->postJson($url, ['plan_hash' => str_repeat('0', 64)])->assertConflict();
        $this->postJson($url, ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.steps.0.status', 'verified');
        $this->postJson($url, ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertSame(1, Watchlist::query()->where('profile_id', $profile->id)->where('name', 'AI List')->count());
    }

    public function test_expired_and_stale_approvals_do_not_execute(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $plan = $this->preview($envelope);
        $run->update(['approval_expires_at' => now()->subSecond()]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'expired');
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $plan = $this->preview($envelope);
        Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'Concurrent change', 'is_default' => false, 'sort_order' => 1]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'stale');
        self::assertFalse(Watchlist::query()->where('profile_id', $profile->id)->where('name', 'AI List')->exists());
    }

    public function test_destructive_confirmation_is_required(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'Keep me', 'sort_order' => 0]);
        $row = Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'Delete me', 'is_default' => false, 'sort_order' => 1]);
        $plan = $this->preview($envelope, [['tool' => 'watchlist.delete', 'arguments' => ['id' => $row->id], 'reason' => 'Delete requested list']]);
        $url = '/api/ai/assistant/runs/'.$run->id.'/approve';
        $this->actingAs($user)->postJson($url, ['plan_hash' => $plan['plan_hash']])->assertUnprocessable()->assertJsonPath('error.code', 'destructive_confirmation_required');
        $this->postJson($url, ['plan_hash' => $plan['plan_hash'], 'destructive_confirmation' => true])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_partial_failure_commits_only_completed_steps_and_stops(): void
    {
        $this->app->instance(AiToolCatalog::class, new class extends AiToolCatalog {
            public function mutate(string $tool, array $a, \App\Models\PortfolioProfile $p, User $user): array
            {
                $result = parent::mutate($tool, $a, $p, $user);
                if ($a['name'] === 'Second') throw new \RuntimeException('Simulated unexpected domain failure');
                return $result;
            }
        });
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $plan = $this->preview($envelope, array_map(fn ($name) => ['tool' => 'watchlist.create', 'arguments' => ['name' => $name], 'reason' => 'Requested list'], ['First', 'Second', 'Third']));
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'partial_failure')->assertJsonPath('data.steps.0.status', 'verified')->assertJsonPath('data.steps.2.status', 'not_attempted');
        self::assertSame(['First'], Watchlist::query()->where('profile_id', $profile->id)->whereIn('name', ['First', 'Second', 'Third'])->pluck('name')->all());
    }

    public function test_verification_failure_never_reports_success_or_continues(): void
    {
        $this->app->instance(AiToolCatalog::class, new class extends AiToolCatalog {
            public function verify(string $tool, array $a, array $result, \App\Models\PortfolioProfile $p, User $user): bool { return false; }
        });
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $plan = $this->preview($envelope, array_map(fn ($name) => ['tool' => 'watchlist.create', 'arguments' => ['name' => $name], 'reason' => 'Requested list'], ['First', 'Second']));
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'verification_failed')->assertJsonPath('data.steps.1.status', 'not_attempted');
        self::assertSame(['First'], Watchlist::query()->where('profile_id', $profile->id)->whereIn('name', ['First', 'Second', 'Third'])->pluck('name')->all());
    }

    public function test_changed_plan_cannot_reuse_an_approval_hash(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $plan = $this->preview($envelope);
        $changed = $run->fresh()->plan;
        $changed[0]['arguments']['name'] = 'Unapproved';
        $run->update(['plan' => $changed]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertConflict()->assertJsonPath('error.code', 'approval_scope_invalid');
        self::assertFalse(Watchlist::query()->where('name', 'Unapproved')->exists());
    }

    public function test_all_read_tools_preserve_business_state_and_history_retries_replan(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $writes = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) && ! str_contains($query->sql, 'stox_ai_agent_runs')) $writes[] = $query->sql;
        });
        $catalog = app(AiToolCatalog::class);
        foreach (array_diff(AiToolCatalog::READS, ['watchlist.items', 'screener.runs']) as $tool) {
            self::assertArrayHasKey('availability', $catalog->read($tool, [], $profile, $user));
        }
        self::assertSame([], $writes);
        config(['ai_runtime.enabled' => false]);
        $this->actingAs($user)->withHeader('X-Profile-Id', $profile->id)->postJson('/api/ai/assistant/runs', ['objective' => 'Ignored replacement', 'retry_run_id' => $run->id])->assertOk()->assertJsonPath('data.objective', $run->objective)->assertJsonPath('data.plan', null);
        self::assertSame(2, AiAgentRun::query()->where('user_id', $user->id)->count());
    }

    public function test_screener_draft_creation_preserves_library_lifecycle_and_readback(): void
    {
        [$user, $profile, $run, $delegation] = $this->runFixture();
        $envelope = ['schema_version' => '1.0', 'artifact_type' => 'screener', 'slug' => 'ai_research', 'name' => 'AI Research', 'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'], 'definition' => ['root' => ['type' => 'condition', 'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]], 'operator' => 'gte', 'right' => ['type' => 'constant', 'value' => 50]]]];
        $before = \App\Models\Screener::query()->count();
        $plan = $this->preview($delegation, [['tool' => 'screener.create', 'arguments' => ['envelope' => $envelope], 'reason' => 'Prepare requested draft']]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed');
        $artifact = \App\Models\ReusableArtifact::query()->where('owner_user_id', $user->id)->where('slug', 'ai_research')->firstOrFail();
        self::assertSame('draft', $artifact->versions()->firstOrFail()->status);
        self::assertSame($before, \App\Models\Screener::query()->count());
        [$user, $profile, $next, $delegation] = $this->runFixture($user);
        $draft = $artifact->versions()->firstOrFail();
        $content = $draft->content_json;
        $content['name'] = 'Updated draft';
        $plan = $this->preview($delegation, [['tool' => 'artifact.update_draft', 'arguments' => ['id' => $draft->id, 'content' => $content], 'reason' => 'Update requested draft']]);
        $this->postJson('/api/ai/assistant/runs/'.$next->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertSame('Updated draft', $draft->fresh()->content_json['name']);
        self::assertSame('draft', $draft->fresh()->status);
    }

    public function test_watchlist_add_remove_and_rename_are_verified(): void
    {
        [$user, $profile, $run, $delegation] = $this->runFixture();
        $row = Watchlist::query()->where('profile_id', $profile->id)->firstOrFail();
        $stock = \App\Models\Stock::query()->create(['symbol' => 'AGENTSTOCK', 'name' => 'Agent stock', 'exchange' => 'NSE', 'series' => 'EQ', 'is_active' => true]);
        $plan = $this->preview($delegation, [['tool' => 'watchlist.add_stock', 'arguments' => ['id' => $row->id, 'stock_id' => $stock->id, 'note' => '  Note  '], 'reason' => 'Add requested stock'], ['tool' => 'watchlist.rename', 'arguments' => ['id' => $row->id, 'name' => 'Renamed'], 'reason' => 'Rename requested list']]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertSame('Note', $row->items()->firstOrFail()->note);
        self::assertSame('Renamed', $row->fresh()->name);
    }

    public function test_gateway_updates_only_the_selected_active_strategy(): void
    {
        [$user, $profile, $run, $delegation] = $this->runFixture();
        $first = app(\App\Services\StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = \App\Models\TradingStrategy::query()->create(['profile_id' => $profile->id, 'name' => 'Strategy B', 'slug' => 'strategy_b', 'status' => 'active', 'is_factory' => false]);
        $version = $this->createTestStrategyVersion(['strategy_id' => $second->id, 'version' => 1, 'version_label' => '1.0', 'config_json' => $first->activeVersion->config_json, 'status' => 'active']);
        $second->forceFill(['active_version_id' => $version->id])->save();
        $envelope = app(\App\Services\Artifacts\StrategyArtifactRegistry::class)->get((string) $second->id, $profile);
        $before = $first->fresh()->getAttributes();
        $envelope['name'] = 'Selected B';
        $plan = $this->preview($delegation, [['tool' => 'strategy.update', 'arguments' => ['id' => $second->id, 'envelope' => $envelope], 'reason' => 'Rename selected strategy']]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertSame($before, $first->fresh()->getAttributes());
        self::assertSame('Selected B', $second->fresh()->name);
    }

    public function test_legacy_screener_update_verifies_intent_despite_list_presentation_metadata(): void
    {
        [$user, $profile, $run, $delegation] = $this->runFixture();
        $screener = \App\Models\Screener::query()->create(['profile_id' => $profile->id, 'name' => 'Before', 'slug' => 'before', 'scope' => 'all_equities', 'is_factory' => false, 'is_enabled' => true, 'definition_json' => ['root' => ['type' => 'condition', 'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]], 'operator' => 'gte', 'right' => ['type' => 'constant', 'value' => 50]]]]);
        $envelope = app(\App\Services\Artifacts\ScreenerArtifactRegistry::class)->get((string) $screener->id, $profile);
        $envelope['name'] = 'After';
        $plan = $this->preview($delegation, [['tool' => 'screener.update', 'arguments' => ['id' => $screener->id, 'envelope' => $envelope], 'reason' => 'Rename requested screener']]);
        $this->actingAs($user)->postJson('/api/ai/assistant/runs/'.$run->id.'/approve', ['plan_hash' => $plan['plan_hash']])->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertSame('After', $screener->fresh()->name);
        $returned = app(\App\Services\Artifacts\ScreenerArtifactRegistry::class)->get((string) $screener->id, $profile);
        $envelope['name'] = 'Never applied';
        self::assertFalse(app(AiToolCatalog::class)->verify('screener.update', ['id' => $screener->id, 'envelope' => $envelope], $returned, $profile, $user));
        $screener->update(['is_enabled' => false]);
        [$user, $profile, $next, $delegation] = $this->runFixture($user);
        $this->toolCall($delegation, ['operation' => 'preview', 'plan' => [['tool' => 'screener.update', 'arguments' => ['id' => $screener->id, 'envelope' => $envelope], 'reason' => 'Must not silently re-enable']]])->assertUnprocessable()->assertJsonPath('error.code', 'disabled_screener_update_not_supported');
        self::assertFalse($screener->fresh()->is_enabled);

    }

    public function test_read_call_limit_and_no_business_writes(): void
    {
        [$user, $profile, $run, $envelope] = $this->runFixture();
        $before = Watchlist::query()->count();
        for ($i = 0; $i < 8; $i++) $this->toolCall($envelope, ['operation' => 'read', 'tool' => 'watchlist.list', 'arguments' => []])->assertOk();
        $this->toolCall($envelope, ['operation' => 'read', 'tool' => 'watchlist.list', 'arguments' => []])->assertUnprocessable()->assertJsonPath('error.code', 'max_steps_exceeded');
        self::assertSame($before, Watchlist::query()->count());
        self::assertCount(8, $run->fresh()->trace);
    }
}