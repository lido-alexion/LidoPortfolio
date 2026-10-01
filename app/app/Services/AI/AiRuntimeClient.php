<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiRuntimeClient
{
    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('ai_runtime.base_url'), '/'))
            ->acceptJson()
            ->timeout((float) config('ai_runtime.timeout_seconds', 20));

        $secret = (string) config('ai_runtime.shared_secret');
        return $secret !== '' ? $request->withHeaders(['X-StoX-AI-Service-Key' => $secret]) : $request;
    }

    public function capabilities(): array
    {
        $response = $this->client()->get('/internal/v1/capabilities');
        if ($response->failed()) {
            throw new RuntimeException('AI runtime capability catalog unavailable.');
        }
        return (array) $response->json('data', []);
    }

    public function infer(string $capability, array $input, array $options = []): array
    {
        if (! config('ai_runtime.enabled', false)) {
            throw new RuntimeException('AI runtime is disabled.');
        }

        $response = $this->client()->post('/internal/v1/inference', [
            'request_id' => $options['request_id'] ?? (string) str()->uuid(),
            'capability_id' => $capability,
            'trace_id' => $options['trace_id'] ?? request()?->header('X-Request-ID'),
            'context' => ['user_id' => $options['user_id'] ?? null, 'account_id' => $options['account_id'] ?? null],
            'input' => $input,
            'output_schema' => $options['output_schema'] ?? null,
            'stream' => false,
        ]);
        if ($response->failed()) {
            throw new RuntimeException('AI runtime inference failed.');
        }
        return (array) $response->json('data', []);
    }

    /** @return \Psr\Http\Message\StreamInterface */
    public function streamDocumentation(array $input, array $options = []): \Psr\Http\Message\StreamInterface
    {
        if (! config('ai_runtime.enabled', false)) {
            throw new RuntimeException('AI runtime is disabled.');
        }
        $response = $this->client()->timeout((float) config('ai_runtime.stream_timeout_seconds', 120))
            ->withOptions(['stream' => true])->post('/internal/v1/inference/stream', [
                'request_id' => $options['request_id'] ?? (string) str()->uuid(), 'capability_id' => 'documentation_chat',
                'trace_id' => $options['trace_id'] ?? request()?->header('X-Request-ID'),
                'context' => ['user_id' => $options['user_id'] ?? null, 'account_id' => $options['account_id'] ?? null],
                'input' => $input, 'stream' => true,
            ]);
        if ($response->failed()) { throw new RuntimeException('AI runtime stream failed.'); }
        return $response->toPsrResponse()->getBody();
    }
}
