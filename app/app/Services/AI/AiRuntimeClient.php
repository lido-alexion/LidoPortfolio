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

        $secret = config('ai_runtime.shared_secret');
        return $secret ? $request->withToken((string) $secret) : $request;
    }

    public function capabilities(): array
    {
        $response = $this->client()->get('/v1/capabilities');
        if ($response->failed()) {
            throw new RuntimeException('AI runtime capability catalog unavailable.');
        }
        return (array) $response->json('capabilities', []);
    }

    public function infer(string $capability, array $input, array $options = []): array
    {
        if (! config('ai_runtime.enabled', false)) {
            throw new RuntimeException('AI runtime is disabled.');
        }

        $response = $this->client()->post('/v1/infer', [
            'request_id' => $options['request_id'] ?? (string) str()->uuid(),
            'capability_id' => $capability,
            'input' => $input,
            'output_schema' => $options['output_schema'] ?? null,
            'stream' => false,
        ]);
        if ($response->failed()) {
            throw new RuntimeException('AI runtime inference failed.');
        }
        return (array) $response->json();
    }
}
