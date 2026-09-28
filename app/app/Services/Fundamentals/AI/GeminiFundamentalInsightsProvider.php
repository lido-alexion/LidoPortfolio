<?php

namespace App\Services\Fundamentals\AI;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeminiFundamentalInsightsProvider implements FundamentalInsightsAiProvider
{
    public function __construct(
        protected FundamentalInsightsResponseValidator $validator,
    ) {}

    public function id(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('fundamentals_ai.gemini.api_key', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $deterministic
     */
    public function generate(Stock $stock, array $deterministic): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error_code' => 'not_configured', 'error_message' => 'Gemini API key is not configured.'];
        }

        $model = (string) config('fundamentals_ai.gemini.model', 'gemini-2.0-flash');
        $timeout = (float) config('fundamentals_ai.timeout_seconds', 30);
        $apiKey = (string) config('fundamentals_ai.gemini.api_key');
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model),
        );

        $prompt = $this->buildPrompt($stock, $deterministic);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($url.'?key='.rawurlencode($apiKey), [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.2,
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

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            return ['ok' => false, 'error_code' => 'empty_response', 'error_message' => 'Gemini returned no text payload.'];
        }

        $parsed = json_decode(trim($text), true);
        if (! is_array($parsed)) {
            return ['ok' => false, 'error_code' => 'invalid_json', 'error_message' => 'Gemini response was not valid JSON.'];
        }

        $insights = $this->validator->normalize($parsed);
        if ($insights === null) {
            return ['ok' => false, 'error_code' => 'contract_invalid', 'error_message' => 'Gemini JSON did not match the insights contract.'];
        }

        $usage = $response->json('usageMetadata');

        return [
            'ok' => true,
            'insights' => $insights,
            'telemetry' => [
                'model' => $model,
                'input_tokens' => isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
                'output_tokens' => isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $deterministic
     */
    protected function buildPrompt(Stock $stock, array $deterministic): string
    {
        $payload = json_encode([
            'symbol' => $stock->symbol,
            'exchange' => $stock->exchange,
            'name' => $stock->name,
            'deterministic_signals' => $deterministic,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are StoX fundamental insights assistant. Use the deterministic_signals as authoritative facts.
Return ONLY JSON matching this schema (no markdown):
{
  "summary": "max two sentences, material only",
  "positive_signals": [],
  "risk_signals": [],
  "watch_items": [],
  "follow_up_checks": [],
  "data_sufficiency": {"rating":"high|medium|low","missing_information":[]}
}
Do not give buy/sell/hold advice or price targets. Omit routine observations already obvious from tables.
Input:
{$payload}
PROMPT;
    }

}
