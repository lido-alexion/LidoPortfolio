<?php

namespace App\Services\Fundamentals\AI;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalDataService;
use Illuminate\Support\Facades\Log;

class FundamentalInsightsAiOrchestrator
{
    /** @var array<string, FundamentalInsightsAiProvider> */
    protected array $providers;

    public function __construct(
        GeminiFundamentalInsightsProvider $gemini,
        CodexFundamentalInsightsProvider $codex,
        protected FundamentalDataService $fundamentals,
    ) {
        $this->providers = [
            $gemini->id() => $gemini,
            $codex->id() => $codex,
        ];
    }

    /**
     * @param  array<string, mixed>  $deterministic
     * @return array<string, mixed>
     */
    public function enrich(Stock $stock, array $deterministic): array
    {
        $order = $this->providerOrder();
        $errors = [];

        foreach ($order as $index => $providerId) {
            $provider = $this->providers[$providerId] ?? null;
            if ($provider === null || ! $provider->isConfigured()) {
                continue;
            }

            $result = $provider->generate($stock, $deterministic);
            if ($result['ok'] ?? false) {
                $insights = is_array($result['insights'] ?? null) ? $result['insights'] : [];
                $merged = array_merge($deterministic, $insights);
                $merged['deterministic'] = $deterministic;
                $merged['ai_interpretation'] = $insights;
                $telemetry = is_array($result['telemetry'] ?? null) ? $result['telemetry'] : [];
                $merged['ai'] = [
                    'status' => 'ok',
                    'provider' => $providerId,
                    'failover_from' => $errors === [] ? null : array_column($errors, 'provider'),
                    'telemetry' => array_merge($telemetry, [
                        'provider_role' => $index === 0 ? 'primary' : 'fallback',
                    ]),
                ];

                return $merged;
            }

            $errors[] = [
                'provider' => $providerId,
                'code' => $result['error_code'] ?? 'unknown',
                'message' => $result['error_message'] ?? '',
            ];
            Log::info('fundamentals_ai.provider_failed', [
                'provider' => $providerId,
                'stock_id' => $stock->id,
                'code' => $result['error_code'] ?? 'unknown',
            ]);
        }

        return array_merge($deterministic, [
            'ai' => [
                'status' => 'unavailable',
                'provider' => null,
                'errors' => $errors,
                'telemetry' => [
                    'provider_role' => null,
                    'error_code' => $errors[0]['code'] ?? 'all_providers_failed',
                ],
            ],
        ]);
    }

    /**
     * Admin connectivity probe for a single provider (FEAT-062 §8.3).
     *
     * @param  array<string, mixed>  $deterministic
     * @return array<string, mixed>
     */
    public function probeProvider(Stock $stock, string $providerId, array $deterministic): array
    {
        $provider = $this->providers[$providerId] ?? null;
        if ($provider === null) {
            return [
                'ok' => false,
                'error_code' => 'unknown_provider',
                'error_message' => 'Unknown provider id.',
            ];
        }

        if (! $provider->isConfigured()) {
            return [
                'ok' => false,
                'error_code' => 'not_configured',
                'error_message' => 'Provider is not configured.',
            ];
        }

        $result = $provider->generate($stock, $deterministic);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'provider' => $providerId,
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? null,
            'telemetry' => is_array($result['telemetry'] ?? null) ? $result['telemetry'] : [],
        ];
    }

    /**
     * @return list<string>
     */
    protected function providerOrder(): array
    {
        $primary = $this->fundamentals->resolvedAiInsightsPrimaryProvider();
        $secondary = (string) config('fundamentals_ai.secondary_provider', 'codex');
        if ($secondary === $primary) {
            $secondary = $primary === 'gemini' ? 'codex' : 'gemini';
        }
        $order = [];
        foreach ([$primary, $secondary] as $id) {
            if ($id !== '' && ! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function adminDiagnostics(): array
    {
        $order = $this->providerOrder();
        $providers = [];
        foreach ($this->providers as $id => $provider) {
            $role = match (true) {
                $order !== [] && $id === $order[0] => 'primary',
                isset($order[1]) && $id === $order[1] => 'secondary',
                default => 'standby',
            };
            $providers[] = [
                'id' => $id,
                'role' => $role,
                'configured' => $provider->isConfigured(),
                'in_failover_chain' => in_array($id, $order, true),
            ];
        }

        $override = $this->fundamentals->settings()->ai_insights_primary_provider;

        return [
            'enabled' => (bool) config('fundamentals_ai.enabled', false),
            'primary_provider' => $order[0] ?? null,
            'secondary_provider' => $order[1] ?? null,
            'primary_provider_source' => in_array($override, ['gemini', 'codex'], true) ? 'database' : 'env',
            'primary_provider_override' => in_array($override, ['gemini', 'codex'], true) ? $override : null,
            'providers' => $providers,
            'usage' => app(FundamentalAiUsageService::class)->adminUsageSummary(),
        ];
    }
}
