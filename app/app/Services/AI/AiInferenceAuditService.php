<?php

namespace App\Services\AI;

use App\Models\AiBudgetLimit;
use App\Models\AiInferenceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AiInferenceAuditService
{
    public function record(array $event): AiInferenceEvent
    {
        return DB::transaction(function () use ($event) {
            $cost = (float) data_get($event, 'usage.estimated_cost', 0);
            $month = CarbonImmutable::now('UTC')->startOfMonth();
            foreach ((array) data_get($event, 'budget_scopes', []) as $scope) {
                $budget = AiBudgetLimit::query()->lockForUpdate()->firstOrCreate(['scope' => $scope], ['period' => 'monthly', 'period_started_at' => $month]);
                if ($budget->period === 'monthly' && (! $budget->period_started_at || $budget->period_started_at->lt($month))) { $budget->update(['spent' => 0, 'period_started_at' => $month]); }
                $budget->increment('spent', $cost);
            }
            return AiInferenceEvent::query()->create([
                'request_id' => $event['request_id'], 'capability_id' => $event['capability'], 'provider' => data_get($event, 'selected_path.provider'),
                'model' => data_get($event, 'selected_path.model'), 'outcome' => $event['status'], 'error_code' => data_get($event, 'error.code'),
                'input_tokens' => data_get($event, 'usage.input_tokens'), 'output_tokens' => data_get($event, 'usage.output_tokens'),
                'estimated_cost' => $cost, 'routing_trace' => $event['routing_trace'] ?? [], 'trace_id' => $event['trace_id'] ?? null,
                'prompt_id' => data_get($event, 'prompt.id'), 'prompt_version' => data_get($event, 'prompt.version'),
                'user_id' => data_get($event, 'context.user_id'), 'account_id' => data_get($event, 'context.account_id'),
                'provenance' => $event['provenance'] ?? [], 'usage' => $event['usage'] ?? [], 'latency_ms' => $event['latency_ms'] ?? null,
            ]);
        });
    }
}
