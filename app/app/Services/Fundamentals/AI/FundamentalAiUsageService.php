<?php

namespace App\Services\Fundamentals\AI;

use App\Models\User;
use App\Models\V7\FundamentalAiInvocation;
use Carbon\Carbon;
class FundamentalAiUsageService
{
    public function allowInvocation(?User $user): bool
    {
        $globalMax = (int) config('fundamentals_ai.limits.daily_global_max', 0);
        if ($globalMax > 0 && $this->countToday() >= $globalMax) {
            return false;
        }

        $userMax = (int) config('fundamentals_ai.limits.daily_per_user_max', 0);
        if ($user !== null && $userMax > 0 && $this->countTodayForUser((int) $user->id) >= $userMax) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $insightsPayload  Full insights array including `ai` block.
     * @param  array<string, mixed>  $telemetry
     */
    public function record(
        ?User $user,
        ?int $stockId,
        array $insightsPayload,
        int $latencyMs,
        array $telemetry = [],
        string $featureKey = 'fundamental_signals',
    ): void {
        $ai = is_array($insightsPayload['ai'] ?? null) ? $insightsPayload['ai'] : [];

        $provider = $ai['provider'] ?? $telemetry['provider'] ?? null;
        $model = $telemetry['model'] ?? null;
        $inputTokens = isset($telemetry['input_tokens']) ? (int) $telemetry['input_tokens'] : null;
        $outputTokens = isset($telemetry['output_tokens']) ? (int) $telemetry['output_tokens'] : null;

        FundamentalAiInvocation::query()->create([
            'user_id' => $user?->id,
            'stock_id' => $stockId,
            'feature_key' => $featureKey,
            'provider' => $provider,
            'provider_role' => $telemetry['provider_role'] ?? null,
            'status' => (string) ($ai['status'] ?? 'unknown'),
            'failover_from' => $ai['failover_from'] ?? null,
            'latency_ms' => max(0, $latencyMs),
            'prompt_version' => (string) config('fundamentals_ai.prompt_version', 'fundamental-signals-v1'),
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_usd' => $this->estimateCostUsd($provider, $model, $inputTokens, $outputTokens),
            'error_code' => $telemetry['error_code'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminUsageSummary(): array
    {
        $start = $this->todayStart();

        return [
            'limits' => [
                'daily_global_max' => (int) config('fundamentals_ai.limits.daily_global_max', 0),
                'daily_per_user_max' => (int) config('fundamentals_ai.limits.daily_per_user_max', 0),
            ],
            'today' => [
                'global_invocations' => $this->countToday(),
                'ok_invocations' => $this->countTodayByStatus('ok'),
                'input_tokens' => $this->sumTodayColumn('input_tokens'),
                'output_tokens' => $this->sumTodayColumn('output_tokens'),
                'estimated_cost_usd' => $this->sumTodayColumn('estimated_cost_usd'),
                'since' => $start->toIso8601String(),
            ],
            'prompt_version' => (string) config('fundamentals_ai.prompt_version', 'fundamental-signals-v1'),
        ];
    }

    public function countToday(): int
    {
        return (int) FundamentalAiInvocation::query()
            ->where('created_at', '>=', $this->todayStart())
            ->count();
    }

    public function countTodayForUser(int $userId): int
    {
        return (int) FundamentalAiInvocation::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $this->todayStart())
            ->count();
    }

    private function countTodayByStatus(string $status): int
    {
        return (int) FundamentalAiInvocation::query()
            ->where('status', $status)
            ->where('created_at', '>=', $this->todayStart())
            ->count();
    }

    private function todayStart(): Carbon
    {
        return now()->startOfDay();
    }

    private function sumTodayColumn(string $column): float
    {
        return (float) FundamentalAiInvocation::query()
            ->where('created_at', '>=', $this->todayStart())
            ->sum($column);
    }

    public function estimateCostUsd(?string $provider, ?string $model, ?int $inputTokens, ?int $outputTokens): ?float
    {
        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $rates = $this->resolveCostRates($provider, $model);
        if ($rates === null) {
            return null;
        }

        $input = ($inputTokens ?? 0) / 1_000_000 * (float) $rates['input'];
        $output = ($outputTokens ?? 0) / 1_000_000 * (float) $rates['output'];

        return round($input + $output, 6);
    }

    /**
     * @return array{input: float, output: float}|null
     */
    private function resolveCostRates(?string $provider, ?string $model): ?array
    {
        $table = config('fundamentals_ai.estimated_cost_per_million_tokens', []);
        if (! is_array($table)) {
            return null;
        }

        if ($model !== null && isset($table[$model]) && is_array($table[$model])) {
            return $this->normalizeRatePair($table[$model]);
        }

        if ($provider !== null && isset($table[$provider]) && is_array($table[$provider])) {
            return $this->normalizeRatePair($table[$provider]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pair
     * @return array{input: float, output: float}|null
     */
    private function normalizeRatePair(array $pair): ?array
    {
        if (! isset($pair['input'], $pair['output']) || ! is_numeric($pair['input']) || ! is_numeric($pair['output'])) {
            return null;
        }

        return ['input' => (float) $pair['input'], 'output' => (float) $pair['output']];
    }
}
