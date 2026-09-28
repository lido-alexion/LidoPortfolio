<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\User;
use App\Services\Fundamentals\AI\FundamentalAiUsageService;
use App\Services\Fundamentals\AI\FundamentalInsightsAiOrchestrator;
use Carbon\Carbon;

/**
 * V8 FEAT-062 — deterministic signals first, provider-neutral AI layer with failover.
 */
class AIInsightsService
{
    public function __construct(
        protected FundamentalSignalsService $signals,
        protected FundamentalInsightsAiOrchestrator $orchestrator,
        protected FundamentalAiUsageService $usage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function enrich(Stock $stock, ?Carbon $asOf = null, array $deterministic = [], ?User $user = null): array
    {
        $deterministic = $deterministic !== [] ? $deterministic : $this->signals->deterministicInsights($stock, $asOf);

        if (! config('fundamentals_ai.enabled', false)) {
            return array_merge($deterministic, [
                'ai' => [
                    'status' => 'disabled',
                    'provider' => null,
                ],
            ]);
        }

        if (! $this->usage->allowInvocation($user)) {
            return array_merge($deterministic, [
                'ai' => [
                    'status' => 'rate_limited',
                    'provider' => null,
                ],
            ]);
        }

        $started = microtime(true);
        $result = $this->orchestrator->enrich($stock, $deterministic);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $telemetry = is_array($result['ai']['telemetry'] ?? null) ? $result['ai']['telemetry'] : [];
        if (isset($result['ai']['telemetry'])) {
            unset($result['ai']['telemetry']);
        }
        $this->usage->record($user, $stock->id, $result, $latencyMs, $telemetry);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function adminDiagnostics(): array
    {
        return $this->orchestrator->adminDiagnostics();
    }

    /**
     * @return array<string, mixed>
     */
    public function runAdminProviderTest(Stock $stock, ?string $providerId = null): array
    {
        $deterministic = $this->signals->deterministicInsights($stock, now());
        $started = microtime(true);

        if ($providerId !== null && $providerId !== '') {
            $probe = $this->orchestrator->probeProvider($stock, $providerId, $deterministic);
            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            $telemetry = is_array($probe['telemetry'] ?? null) ? $probe['telemetry'] : [];
            $telemetry['provider_role'] = 'test';
            $this->usage->record(
                null,
                $stock->id,
                [
                    'ai' => [
                        'status' => ($probe['ok'] ?? false) ? 'ok' : 'unavailable',
                        'provider' => $providerId,
                    ],
                ],
                $latencyMs,
                array_merge($telemetry, ['error_code' => $probe['error_code'] ?? null]),
                'fundamental_signals_admin_test',
            );

            return $probe;
        }

        $result = $this->orchestrator->enrich($stock, $deterministic);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $telemetry = is_array($result['ai']['telemetry'] ?? null) ? $result['ai']['telemetry'] : [];
        if (isset($result['ai']['telemetry'])) {
            unset($result['ai']['telemetry']);
        }
        $this->usage->record(null, $stock->id, $result, $latencyMs, $telemetry, 'fundamental_signals_admin_test');

        return [
            'ok' => ($result['ai']['status'] ?? '') === 'ok',
            'provider' => $result['ai']['provider'] ?? null,
            'insights' => $result,
        ];
    }
}
