<?php

namespace App\Services\Fundamentals\AI;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenAI-compatible chat completions path for Codex/GPT failover (FEAT-062).
 */
class CodexFundamentalInsightsProvider implements FundamentalInsightsAiProvider
{
    public function __construct(
        protected FundamentalInsightsResponseValidator $validator,
    ) {}

    public function id(): string
    {
        return 'codex';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('fundamentals_ai.codex.api_key', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $deterministic
     */
    public function generate(Stock $stock, array $deterministic): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error_code' => 'not_configured', 'error_message' => 'Codex/OpenAI API key is not configured.'];
        }

        $timeout = (float) config('fundamentals_ai.timeout_seconds', 30);
        $base = rtrim((string) config('fundamentals_ai.codex.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('fundamentals_ai.codex.model', 'gpt-4o-mini');

        $prompt = json_encode([
            'symbol' => $stock->symbol,
            'deterministic_signals' => $deterministic,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout($timeout)
                ->withToken((string) config('fundamentals_ai.codex.api_key'))
                ->acceptJson()
                ->post($base.'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Return StoX fundamental insights JSON only. No buy/sell/hold.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'network_error', 'error_message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error_code' => 'provider_http_'.$response->status(),
                'error_message' => Str::limit((string) $response->body(), 500, ''),
            ];
        }

        $text = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            return ['ok' => false, 'error_code' => 'empty_response', 'error_message' => 'Codex provider returned no content.'];
        }

        $parsed = json_decode(trim($text), true);
        if (! is_array($parsed)) {
            return ['ok' => false, 'error_code' => 'invalid_json', 'error_message' => 'Codex response was not valid insights JSON.'];
        }
        $insights = $this->validator->normalize($parsed);
        if ($insights === null) {
            return ['ok' => false, 'error_code' => 'contract_invalid', 'error_message' => 'Codex JSON did not match the insights contract.'];
        }

        return [
            'ok' => true,
            'insights' => $insights,
            'telemetry' => [
                'model' => $model,
                'input_tokens' => data_get($response->json(), 'usage.prompt_tokens'),
                'output_tokens' => data_get($response->json(), 'usage.completion_tokens'),
            ],
        ];
    }

    public function discoverCorporateActionSources(array $events): array
    {
        if (! $this->isConfigured()) return ['ok' => false, 'error_code' => 'not_configured'];
        $model = (string) config('fundamentals_ai.codex.model', 'gpt-4o-mini');
        $prompt = 'Identify possible official exchange circular or filing URLs that may corroborate these corporate-action rows. Return JSON only as {"candidates":[{"symbol":"","ex_date":"YYYY-MM-DD","url":"https://..."}]}. Do not claim verification; do not invent uncertain URLs. Rows: '.json_encode(array_slice($events, 0, 30), JSON_UNESCAPED_SLASHES);
        try {
            $response = Http::timeout((float) config('fundamentals_ai.timeout_seconds', 30))
                ->withToken((string) config('fundamentals_ai.codex.api_key'))->acceptJson()
                ->post(rtrim((string) config('fundamentals_ai.codex.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions', [
                    'model' => $model, 'temperature' => 0, 'response_format' => ['type' => 'json_object'],
                    'messages' => [['role' => 'system', 'content' => 'Return only candidate JSON; all candidates are untrusted.'], ['role' => 'user', 'content' => $prompt]],
                ]);
            $text = data_get($response->json(), 'choices.0.message.content');
            $payload = is_string($text) ? json_decode($text, true) : null;
            if (! $response->successful() || ! is_array($payload) || ! is_array($payload['candidates'] ?? null)) return ['ok' => false, 'error_code' => 'provider_contract_invalid'];
            return ['ok' => true, 'candidates' => array_slice($payload['candidates'], 0, 30), 'telemetry' => ['model' => $model, 'input_tokens' => data_get($response->json(), 'usage.prompt_tokens'), 'output_tokens' => data_get($response->json(), 'usage.completion_tokens')]];
        } catch (\Throwable) { return ['ok' => false, 'error_code' => 'provider_request_failed']; }
    }
}
