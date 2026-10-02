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

    public function test_only_admin_can_change_live_global_concurrency(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user)->putJson('/api/admin/ai-platform/concurrency', ['global_max_concurrency' => 2])->assertForbidden();
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/ai-platform/concurrency', ['global_max_concurrency' => 0])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/admin/ai-platform/concurrency', ['global_max_concurrency' => 2])->assertOk();
        self::assertSame(2, app(\App\Services\AI\AiPlatformConfigurationService::class)->projection()['global_max_concurrency']);
    }

    public function test_laravel_projects_governed_configuration_only_to_authenticated_runtime(): void
    {
        config(['ai_runtime.shared_secret' => 'test-runtime-key']);
        AiCapability::query()->updateOrCreate(['capability_id' => 'documentation_chat'], ['owner' => 'V9-AI-001', 'enabled' => true, 'path_order' => ['mock']]);
        AiProviderPath::query()->create(['path_id' => 'mock', 'provider' => 'deterministic', 'model' => 'test', 'priority' => 1, 'enabled' => true, 'config' => ['deterministic_response' => 'grounded']]);
        AiPrompt::query()->updateOrCreate(['prompt_id' => 'documentation_chat', 'version' => 2], ['template' => 'Use maintained documentation only.', 'active' => true]);

        $this->getJson('/api/internal/v1/ai-runtime/configuration')->assertUnauthorized();
        $this->withHeader('X-StoX-AI-Service-Key', 'test-runtime-key')->getJson('/api/internal/v1/ai-runtime/configuration')
            ->assertOk()->assertJsonPath('success', true)->assertJsonFragment(['capability_id' => 'documentation_chat', 'owner' => 'V9-AI-001', 'enabled' => true, 'path_order' => ['mock'], 'output_schema' => null, 'prompt_id' => 'documentation_chat', 'streaming' => true, 'max_concurrency' => 4])
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

    public function test_provider_reported_cost_cannot_mutate_authoritative_spend(): void
    {
        $budget = \App\Models\AiBudgetLimit::query()->create(['scope' => 'overall', 'hard_limit' => 1, 'spent' => 0.9, 'period' => 'monthly', 'period_started_at' => now()->startOfMonth()]);
        try {
            app(\App\Services\AI\AiInferenceAuditService::class)->record(['request_id' => (string) str()->uuid(), 'capability' => 'documentation_chat', 'status' => 'success', 'usage' => ['estimated_cost' => 999], 'budget_scopes' => ['overall']]);
            self::fail('Successful unreserved inference must not be recorded as free');
        } catch (\Illuminate\Validation\ValidationException $error) {
            self::assertStringContainsString('reservation_required', $error->getMessage());
        }
        self::assertEqualsWithDelta(0.9, (float) $budget->fresh()->spent, 0.000001);
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
