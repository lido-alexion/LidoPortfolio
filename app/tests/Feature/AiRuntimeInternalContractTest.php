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
