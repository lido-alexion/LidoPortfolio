<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AiRuntimeClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiAssistantStreamController extends Controller
{
    /** Browser-facing authenticated proxy; it never exposes the private runtime URL/key. */
    public function stream(Request $request, AiRuntimeClient $runtime): StreamedResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:4000'], 'page_context' => ['nullable', 'array']]);
        return response()->stream(function () use ($request, $runtime, $data): void {
            try {
                $body = $runtime->streamDocumentation($data, ['user_id' => $request->user()?->id, 'trace_id' => $request->header('X-Request-ID')]);
                while (! $body->eof() && ! connection_aborted()) { echo $body->read(8192); if (function_exists('flush')) { flush(); } }
            } catch (\Throwable) {
                echo "event: error\ndata: {\"code\":\"runtime_unavailable\"}\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
