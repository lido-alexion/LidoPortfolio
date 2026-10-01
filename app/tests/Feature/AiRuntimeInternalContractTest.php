<?php

namespace Tests\Feature;

use App\Models\AiCapability;
use App\Models\AiPrompt;
use App\Models\AiProviderPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiRuntimeInternalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_projects_governed_configuration_only_to_authenticated_runtime(): void
    {
        config(['ai_runtime.shared_secret' => 'test-runtime-key']);
        AiCapability::query()->updateOrCreate(['capability_id' => 'documentation_chat'], ['owner' => 'V9-AI-001', 'enabled' => true, 'path_order' => ['mock']]);
        AiProviderPath::query()->create(['path_id' => 'mock', 'provider' => 'deterministic', 'model' => 'test', 'priority' => 1, 'enabled' => true, 'config' => ['deterministic_response' => 'grounded']]);
        AiPrompt::query()->updateOrCreate(['prompt_id' => 'documentation_chat', 'version' => 2], ['template' => 'Use maintained documentation only.', 'active' => true]);

        $this->getJson('/api/internal/v1/ai-runtime/configuration')->assertUnauthorized();
        $this->withHeader('X-StoX-AI-Service-Key', 'test-runtime-key')->getJson('/api/internal/v1/ai-runtime/configuration')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.capabilities.0.capability_id', 'documentation_chat')
            ->assertJsonPath('data.prompts.documentation_chat.version', 2);
    }

    public function test_projection_distinguishes_unlimited_and_exhausted_budgets(): void
    {
        \App\Models\AiBudgetLimit::query()->create(['scope' => 'overall', 'hard_limit' => 1, 'spent' => 1, 'period' => 'monthly', 'period_started_at' => now()->startOfMonth()]);
        \App\Models\AiBudgetLimit::query()->create(['scope' => 'path:unlimited', 'hard_limit' => null]);
        $budgets = app(\App\Services\AI\AiPlatformConfigurationService::class)->projection()['budgets'];
        self::assertCount(1, $budgets);
        self::assertSame('overall', $budgets[0]['scope']);
        self::assertSame(1.0, $budgets[0]['spent']);
    }

    public function test_authoritative_ledger_records_cost_crossing_a_hard_limit(): void
    {
        $budget = \App\Models\AiBudgetLimit::query()->create(['scope' => 'overall', 'hard_limit' => 1, 'spent' => 0.9, 'period' => 'monthly', 'period_started_at' => now()->startOfMonth()]);
        app(\App\Services\AI\AiInferenceAuditService::class)->record(['request_id' => (string) str()->uuid(), 'capability' => 'documentation_chat', 'status' => 'success', 'usage' => ['estimated_cost' => 0.2], 'budget_scopes' => ['overall']]);
        self::assertEqualsWithDelta(1.1, (float) $budget->fresh()->spent, 0.000001);
    }

    public function test_runtime_client_uses_canonical_private_path_header_and_envelope(): void
    {
        config(['ai_runtime.enabled' => true, 'ai_runtime.shared_secret' => 'test-runtime-key', 'ai_runtime.base_url' => 'http://ai.private']);
        Http::fake(['http://ai.private/internal/v1/inference' => Http::response(['success' => true, 'data' => ['status' => 'success', 'capability_id' => 'documentation_chat']], 200)]);

        $result = app(\App\Services\AI\AiRuntimeClient::class)->infer('documentation_chat', ['question' => 'How do I export?']);

        self::assertSame('success', $result['status']);
        Http::assertSent(fn ($request) => $request->url() === 'http://ai.private/internal/v1/inference'
            && $request->hasHeader('X-StoX-AI-Service-Key', 'test-runtime-key')
            && ! $request->hasHeader('Authorization')
            && $request['capability_id'] === 'documentation_chat');
    }
}
