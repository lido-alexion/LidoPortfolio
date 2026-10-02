<?php

namespace App\Services\AI;

use App\Models\AiInferenceEvent;
use Illuminate\Support\Facades\DB;

class AiInferenceAuditService
{
    public function record(array $event): AiInferenceEvent
    {
        return app(AiBudgetReservationService::class)->locked(function () use ($event) {
            $existing = AiInferenceEvent::query()->where('request_id', $event['request_id'])->first();
            if ($existing) {
                return $existing;
            }
            $reservations = DB::table('stox_ai_budget_reservations')->where('request_id', $event['request_id'])->get();
            if ($reservations->isEmpty() && $event['status'] === 'success') {
                throw \Illuminate\Validation\ValidationException::withMessages(['reservation' => 'reservation_required']);
            }
            if ($reservations->contains(fn ($row) => $row->state === 'reserved')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['settlement' => 'settlement_pending']);
            }
            $cost = (float) $reservations->sum('settled_cost');
            // Legacy callers may submit diagnostic cost, but cannot mutate spend.
            // Every charge is admitted and settled through the authoritative ledger.
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
