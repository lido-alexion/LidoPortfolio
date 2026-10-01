<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AiRuntimeClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiAssistantStreamController extends Controller
{
    public function feedback(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'helpful' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:500']]);
        $event = \App\Models\AiInferenceEvent::query()->where('request_id', $data['request_id'])->where('capability_id', 'documentation_chat')->where('user_id', $request->user()->id)->firstOrFail();
        \Illuminate\Support\Facades\DB::table('stox_ai_assistant_feedback')->updateOrInsert(
            ['inference_event_id' => $event->id, 'user_id' => $request->user()->id],
            ['helpful' => $data['helpful'], 'comment' => $data['helpful'] ? null : ($data['comment'] ?? null), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }

    /** Browser-facing authenticated proxy; it never exposes the private runtime URL/key. */
    public function stream(Request $request, AiRuntimeClient $runtime): StreamedResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:4000'], 'page_context' => ['nullable', 'array:route,topic,section,visible'],
            'page_context.route' => ['nullable', 'string', 'max:160', 'regex:~^/[a-zA-Z0-9/_-]*$~'],
            'page_context.topic' => ['nullable', 'string', 'max:100'],
            'page_context.section' => ['nullable', 'string', 'max:100'],
            'page_context.visible' => ['nullable', 'array', 'max:20'],
            'page_context.visible.*' => ['array:label,value'],
            'page_context.visible.*.label' => ['required', 'string', 'max:100'],
            'page_context.visible.*.value' => ['required', 'string', 'max:160'],
            'conversation' => ['nullable', 'array', 'max:6'],
            'conversation.*' => ['array:question,answer'],
            'conversation.*.question' => ['required', 'string', 'max:4000'],
            'conversation.*.answer' => ['required', 'string', 'max:6000']]);
        return response()->stream(function () use ($request, $runtime, $data): void {
            try {
                $body = $runtime->streamDocumentation($data, ['user_id' => $request->user()?->id, 'trace_id' => $request->header('X-Request-ID')]);
                foreach (app(\App\Services\AI\AssistantStreamProjection::class)->events($body) as $event) { if (connection_aborted()) { break; } echo $event; if (function_exists('flush')) { flush(); } }
            } catch (\Throwable) {
                echo "event: error\ndata: {\"code\":\"runtime_unavailable\"}\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
