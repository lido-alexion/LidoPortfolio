<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\AiInferenceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_requires_authentication(): void
    {
        $this->postJson('/api/ai/assistant/stream', ['question' => 'Help'])->assertUnauthorized();
        $this->postJson('/api/ai/assistant/feedback', [])->assertUnauthorized();
    }

    public function test_context_cannot_supply_authorization_or_arbitrary_fields(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/ai/assistant/stream', ['question' => 'Help', 'page_context' => ['token' => 'secret']])->assertUnprocessable();
    }

    public function test_authenticated_proxy_sanitizes_private_stream_and_delegates_identity(): void
    {
        $user = User::factory()->create();
        config(['ai_runtime.enabled' => true, 'ai_runtime.base_url' => 'http://ai.private']);
        Http::fake(['http://ai.private/*' => Http::response("event: message.completed\ndata: {\"request_id\":\"r\",\"provider\":\"secret-provider\",\"model\":\"private-model\",\"routing_trace\":[\"internal\"]}\n\n", 200)]);
        $response = $this->actingAs($user)->postJson('/api/ai/assistant/stream', ['question' => 'How do I create a screener?', 'user_id' => 999]);
        $response->assertOk();
        $body = $response->streamedContent();
        self::assertStringNotContainsString('secret-provider', $body);
        self::assertStringNotContainsString('private-model', $body);
        self::assertStringNotContainsString('routing_trace', $body);
        Http::assertSent(fn ($request) => $request['context']['user_id'] === $user->id && ! isset($request['input']['user_id']));
    }

    public function test_feedback_is_linked_to_owned_audit_evidence(): void
    {
        $user = User::factory()->create();
        $event = AiInferenceEvent::query()->create(['request_id' => (string) str()->uuid(), 'capability_id' => 'documentation_chat', 'user_id' => $user->id, 'outcome' => 'success', 'routing_trace' => [['provider' => 'internal']], 'provenance' => [['source_id' => 'journey:scr']]]);
        $this->actingAs($user)->postJson('/api/ai/assistant/feedback', ['request_id' => $event->request_id, 'helpful' => false, 'comment' => 'Needs detail'])->assertOk();
        $this->assertDatabaseHas('stox_ai_assistant_feedback', ['inference_event_id' => $event->id, 'user_id' => $user->id, 'helpful' => false]);
        $this->actingAs(User::factory()->create())->postJson('/api/ai/assistant/feedback', ['request_id' => $event->request_id, 'helpful' => true])->assertNotFound();
    }
}
