<?php

namespace App\Services\AI;

use App\Models\AiBudgetLimit;
use App\Models\AiCapability;
use App\Models\AiProviderPath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Laravel owns all spend. Unknown delivery/execution is charged conservatively. */
class AiBudgetReservationService
{
    public function locked(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            DB::table('stox_ai_ledger_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            return $operation();
        }, 3);
    }

    public function reserve(array $input): array
    {
        return $this->locked(function () use ($input) {
            $this->reconcileLocked();
            $existing = DB::table('stox_ai_budget_reservations')->where('id', $input['id'])->first();
            if ($existing) {
                // Never authorize another provider call for a replayed admission.
                throw ValidationException::withMessages(['id' => 'reservation_already_exists']);
            }
            $capability = AiCapability::query()->where('capability_id', $input['capability_id'])->where('enabled', true)->firstOrFail();
            $path = AiProviderPath::query()->where('path_id', $input['path_id'])->where('enabled', true)->firstOrFail();
            if (! in_array($path->path_id, $capability->path_order ?? [], true)) {
                throw ValidationException::withMessages(['path_id' => 'path_not_allowed']);
            }
            if ($path->provider !== ($input['provider'] ?? $path->provider) || $path->model !== ($input['model'] ?? $path->model)) {
                throw ValidationException::withMessages(['path_id' => 'provider_projection_stale']);
            }
            $config = $path->config ?? [];
            $pricing = $config['pricing'] ?? ($path->provider === 'deterministic' ? ['input_per_million' => 0, 'output_per_million' => 0] : null);
            if (! is_array($pricing) || ! isset($pricing['input_per_million'], $pricing['output_per_million']) || ! is_numeric($pricing['input_per_million']) || ! is_numeric($pricing['output_per_million']) || $pricing['input_per_million'] < 0 || $pricing['output_per_million'] < 0) {
                throw ValidationException::withMessages(['pricing' => 'pricing_unavailable']);
            }
            $maxOutput = max(1, min(100000, (int) ($config['max_output_tokens'] ?? 4096)));
            $cost = $this->cost($pricing, $input['input_token_bound'], $maxOutput);
            $scopes = array_values(array_unique(array_merge(['overall', 'capability:'.$capability->capability_id, 'path:'.$path->path_id], $config['budget_scopes'] ?? [], isset($input['user_id']) ? ['user:'.$input['user_id']] : [])));
            sort($scopes);
            $month = CarbonImmutable::now('UTC')->startOfMonth();
            foreach ($scopes as $scope) {
                $budget = AiBudgetLimit::query()->firstOrCreate(['scope' => $scope], ['period' => 'monthly', 'period_started_at' => $month]);
                $budget = AiBudgetLimit::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
                if ($budget->period === 'monthly' && (! $budget->period_started_at || $budget->period_started_at->lt($month))) {
                    $budget->update(['spent' => 0, 'period_started_at' => $month]);
                }
                $reserved = 0;
                foreach (DB::table('stox_ai_budget_reservations')->where('state', 'reserved')->get() as $reservation) {
                    if (in_array($scope, json_decode($reservation->scopes, true), true)) {
                        $reserved += $this->units($reservation->reserved_cost);
                    }
                }
                if ($budget->hard_limit !== null && $this->units($budget->spent) + $reserved + $this->units($cost) > $this->units($budget->hard_limit)) {
                    throw ValidationException::withMessages(['budget' => 'hard_budget_exhausted:'.$scope]);
                }
            }
            DB::table('stox_ai_budget_reservations')->insert([
                'id' => $input['id'], 'request_id' => $input['request_id'], 'path_id' => $path->path_id,
                'capability_id' => $capability->capability_id, 'scopes' => json_encode($scopes), 'pricing' => json_encode($pricing),
                'reserved_cost' => $cost, 'state' => 'reserved', 'expires_at' => now()->addMinutes(15),
                'period_started_at' => $month, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => $input['id'], 'max_output_tokens' => $maxOutput, 'reserved_cost' => $cost];
        });
    }

    public function settle(string $id, ?array $usage): array
    {
        return $this->locked(function () use ($id, $usage) {
            $row = DB::table('stox_ai_budget_reservations')->where('id', $id)->firstOrFail();
            if ($row->state === 'settled' && ($row->usage !== null || $usage === null)) {
                return ['id' => $id, 'cost' => (float) $row->settled_cost];
            }
            // Missing usage does not mean free inference. Retain the maximum charge.
            $cost = $usage === null ? (float) $row->reserved_cost : $this->cost(json_decode($row->pricing, true), $usage['input_tokens'], $usage['output_tokens']);
            $this->charge($row, $cost - (float) ($row->settled_cost ?? 0));
            DB::table('stox_ai_budget_reservations')->where('id', $id)->update(['state' => 'settled', 'settled_cost' => $cost, 'usage' => $usage === null ? null : json_encode($usage), 'updated_at' => now()]);
            \App\Models\AiInferenceEvent::query()->where('request_id', $row->request_id)->update(['estimated_cost' => DB::table('stox_ai_budget_reservations')->where('request_id', $row->request_id)->sum('settled_cost')]);
            return ['id' => $id, 'cost' => $cost];
        });
    }

    public function refreshProjection(): void
    {
        $this->locked(function () {
            $this->reconcileLocked();
            $month = CarbonImmutable::now('UTC')->startOfMonth();
            AiBudgetLimit::query()->where('period', 'monthly')
                ->where(fn ($query) => $query->whereNull('period_started_at')->orWhere('period_started_at', '<', $month))
                ->update(['spent' => 0, 'period_started_at' => $month]);
        });
    }

    public function reconcile(): void
    {
        $this->locked(fn () => $this->reconcileLocked());
    }

    private function reconcileLocked(): void
    {
        foreach (DB::table('stox_ai_budget_reservations')->where('state', 'reserved')->where('expires_at', '<=', now())->get() as $row) {
            $this->charge($row, (float) $row->reserved_cost);
            DB::table('stox_ai_budget_reservations')->where('id', $row->id)->update(['state' => 'reconciled', 'settled_cost' => $row->reserved_cost, 'updated_at' => now()]);
        }
    }

    private function charge(object $row, float $cost): void
    {
        foreach (json_decode($row->scopes, true) as $scope) {
            $budget = AiBudgetLimit::query()->where('scope', $scope)->lockForUpdate()->firstOrFail();
            // A late prior-month settlement stays attached to its original period.
            if ($budget->period !== 'monthly' || $budget->period_started_at?->equalTo(CarbonImmutable::parse($row->period_started_at))) {
                $budget->increment('spent', $cost);
            }
        }
    }

    /** Compare ledger DECIMAL(18,8) amounts as integer units, not binary floats. */
    private function units(string|float $amount): int
    {
        $decimal = is_float($amount) ? number_format($amount, 8, '.', '') : $amount;
        $negative = str_starts_with($decimal, '-');
        $parts = explode('.', ltrim($decimal, '+-'), 2);
        $units = ((int) $parts[0] * 100000000) + (int) str_pad(substr($parts[1] ?? '', 0, 8), 8, '0');
        return $negative ? -$units : $units;
    }

    private function cost(array $pricing, int $input, int $output): float
    {
        return ceil(($input * (float) $pricing['input_per_million'] + $output * (float) $pricing['output_per_million']) * 100) / 100000000;
    }
}
