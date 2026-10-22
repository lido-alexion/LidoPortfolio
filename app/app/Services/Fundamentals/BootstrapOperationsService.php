<?php

namespace App\Services\Fundamentals;

use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BootstrapOperationsService
{
    public function __construct(private FundamentalBootstrapService $engine) {}

    public function launch(array $input, int $actorId, string $actorName, string $key): BootstrapLaunchResult
    {
        if (isset($input['trigger']) && ! in_array($input['trigger'], ['manual', 'retry'], true)) {
            throw ValidationException::withMessages(['trigger' => 'Use the validated schedule or gap launch adapter.']);
        }
        return $this->launchManaged($input, $actorId, $actorName, $key);
    }

    /** Internal context is accepted only through validated service adapters, never public launch input. */
    private function launchManaged(array $input, int $actorId, string $actorName, string $key, array $context = []): BootstrapLaunchResult
    {
        $actorName = trim($actorName);
        if ($actorId < 1 || $actorName === '' || mb_strlen($actorName) > 255
            || ! User::query()->whereKey($actorId)->where('is_admin', true)->exists()) {
            throw ValidationException::withMessages(['actor' => 'A valid Admin identity is required.']);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid Idempotency-Key is required.']);
        }
        $scope = $input['scope'] ?? null;
        if (! in_array($scope, ['all', 'stocks'], true)) {
            throw ValidationException::withMessages(['scope' => 'Choose all equities or explicit stocks.']);
        }
        $rawIds = $input['stock_ids'] ?? [];
        if (! is_array($rawIds) || count($rawIds) > 500) {
            throw ValidationException::withMessages(['stock_ids' => 'Provide at most 500 eligible stock IDs.']);
        }
        foreach ($rawIds as $stockId) {
            if (!(is_int($stockId) || (is_string($stockId) && ctype_digit($stockId))) || (int) $stockId < 1) {
                throw ValidationException::withMessages(['stock_ids' => 'Every stock ID must be a positive integer.']);
            }
        }
        $stockIds = array_map('intval', $rawIds);
        if (count(array_unique($stockIds)) !== count($stockIds)) {
            throw ValidationException::withMessages(['stock_ids' => 'Stock IDs must be unique.']);
        }
        if (($scope === 'all' && $stockIds !== []) || ($scope === 'stocks' && $stockIds === [])) {
            throw ValidationException::withMessages(['scope' => 'The selected scope and stock IDs do not match.']);
        }
        if (isset($input['force']) && ! is_bool($input['force'])) {
            throw ValidationException::withMessages(['force' => 'Force must be a boolean.']);
        }
        if (isset($input['reason']) && (! is_string($input['reason']) || mb_strlen($input['reason']) > 2000)) {
            throw ValidationException::withMessages(['reason' => 'Reason must be at most 2000 characters.']);
        }
        if (isset($input['trigger']) && ! in_array($input['trigger'], ['manual', 'retry', 'gap_driven', 'scheduled'], true)) {
            throw ValidationException::withMessages(['trigger' => 'Unsupported bootstrap request type.']);
        }
        if (isset($input['origin_run_id']) && (! is_numeric($input['origin_run_id']) || (int) $input['origin_run_id'] < 1)) {
            throw ValidationException::withMessages(['origin_run_id' => 'Origin run ID must be a positive integer.']);
        }
        if (($input['force'] ?? false)) {
            $reason = $input['reason'] ?? null;
            if (! is_string($reason) || mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['reason' => 'A forced rerun requires a reason between 3 and 2000 characters.']);
            }
        }
        $key = hash('sha256', $actorId.':'.$key);
        return DB::transaction(function () use ($input, $actorId, $actorName, $key, $scope, $stockIds, $context): BootstrapLaunchResult {
            DB::table('stox_fund_bootstrap_control')->where('id', 1)->lockForUpdate()->first();
            $existing = FundamentalBootstrapRun::query()->where('idempotency_key', $key)->where('created_by_user_id', $actorId)->first();
            sort($stockIds);
            $snapshot = [
                'managed_version' => FundamentalBootstrapService::OPS_MANAGED_VERSION,
                'origin' => 'admin_fundamentals_bootstrap_operations',
                'scope' => $scope, 'stock_ids' => $stockIds,
                'trigger' => ($input['force'] ?? false) ? 'forced_rerun' : ($input['trigger'] ?? 'manual'), 'force' => (bool) ($input['force'] ?? false),
                'origin_run_id' => isset($input['origin_run_id']) ? (int) $input['origin_run_id'] : null,
                'reason' => $input['reason'] ?? null, 'requested_by' => ['id' => $actorId, 'name' => $actorName],
                'requested_at' => now()->toIso8601String(),
                'gap_receipt' => $context['gap_receipt'] ?? null,
                'schedule_receipt' => $context['schedule_receipt'] ?? null,
            ];
            if ($existing) {
                $old = $existing->request_snapshot ?? [];
                if (($old['scope'] ?? null) !== $snapshot['scope']
                    || ($old['stock_ids'] ?? []) !== $snapshot['stock_ids']
                    || ($old['trigger'] ?? 'manual') !== $snapshot['trigger']
                    || ($old['origin_run_id'] ?? null) !== $snapshot['origin_run_id']
                    || ($old['force'] ?? false) !== $snapshot['force']
                    || ($old['reason'] ?? null) !== $snapshot['reason']
                    || ! BootstrapRequestContext::same($old['gap_receipt'] ?? null, $snapshot['gap_receipt'])
                    || ! BootstrapRequestContext::same($old['schedule_receipt'] ?? null, $snapshot['schedule_receipt'])) {
                    throw ValidationException::withMessages(['Idempotency-Key' => 'This key was already used for a different request.']);
                }
                return new BootstrapLaunchResult($existing, false);
            }
            if (($input['force'] ?? false) && ($scope !== 'stocks' || $stockIds === [])) {
                throw ValidationException::withMessages(['stock_ids' => 'Forced reruns must target explicit stocks.']);
            }
            if (($input['force'] ?? false) && FundamentalBootstrapJob::query()
                ->whereIn('stock_id', $stockIds)
                ->whereIn('status', [FundamentalBootstrapJob::STATUS_COMPLETE_GOOD, FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL])
                ->distinct()->count('stock_id') !== count($stockIds)) {
                throw ValidationException::withMessages(['stock_ids' => 'Every forced-rerun stock must have a prior successful bootstrap item.']);
            }
            // Validate exact target eligibility here and in the engine. An
            if ($scope === 'stocks') {
                $eligible = $this->engine->eligibleStockIds($snapshot['stock_ids']);
                if ($eligible !== $snapshot['stock_ids']) {
                    throw ValidationException::withMessages(['stock_ids' => 'Every selected stock must be an active eligible equity.']);
                }
            }
            $run = $this->engine->createRun($scope, $snapshot['stock_ids'] ?: null, $actorId);
            $run->forceFill([
                'trigger' => $snapshot['trigger'], 'idempotency_key' => $key,
                'request_snapshot' => $snapshot, 'trigger_actor' => $actorName,
                'operator_reason' => $snapshot['reason'],
            ])->save();
            return new BootstrapLaunchResult($run->fresh(), true);
        });
    }

    /** Resolve current compatible gaps, recording the exact selection before run creation commits. */
    public function launchFromGaps(array $gapIds, int $actorId, string $actorName, string $key): BootstrapLaunchResult
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid Idempotency-Key is required.']);
        }
        if ($gapIds === [] || count($gapIds) > 100 || count(array_unique($gapIds)) !== count($gapIds)) {
            throw ValidationException::withMessages(['gap_ids' => 'Select 1 to 100 distinct gaps.']);
        }
        foreach ($gapIds as $id) if (! is_int($id) || $id < 1) {
            throw ValidationException::withMessages(['gap_ids' => 'Every gap ID must be a positive integer.']);
        }
        sort($gapIds);
        return DB::transaction(function () use ($gapIds, $actorId, $actorName, $key): BootstrapLaunchResult {
            DB::table('stox_fund_bootstrap_control')->where('id', 1)->lockForUpdate()->first();
            $existing = FundamentalBootstrapRun::query()->where('idempotency_key', hash('sha256', $actorId.':'.$key))
                ->where('created_by_user_id', $actorId)->first();
            if ($existing) {
                $old = $existing->request_snapshot ?? [];
                if (($old['gap_receipt']['gap_ids'] ?? null) !== $gapIds) {
                    throw ValidationException::withMessages(['Idempotency-Key' => 'This key was already used for a different request.']);
                }
                // A replay returns its immutable target even if the gap was subsequently resolved.
                return $this->launchManaged(['scope' => 'stocks', 'stock_ids' => $old['stock_ids'], 'trigger' => 'gap_driven'],
                    $actorId, $actorName, $key, ['gap_receipt' => $old['gap_receipt']]);
            }
            $rows = \App\Models\V7\BootstrapCoverageGap::query()->whereIn('id', $gapIds)->orderBy('id')->lockForUpdate()->get();
            $stockIds = app(BootstrapCoverageOperationsService::class)->selectedStockIdsForLaunch($gapIds);
            $receipt = [
                'gap_ids' => $gapIds, 'stock_ids' => $stockIds,
                'metric' => $rows->first()->metric_key, 'cadence' => $rows->first()->cadence,
                'execution_scope' => 'whole_stock',
                'selections' => $rows->map(fn ($gap) => [
                    'id' => (int) $gap->id, 'stock_id' => (int) $gap->stock_id,
                    'metric' => $gap->metric_key, 'cadence' => $gap->cadence,
                    'period_end' => $gap->period_end?->toDateString(), 'reason' => $gap->reason,
                ])->all(),
            ];
            return $this->launchManaged(['scope' => 'stocks', 'stock_ids' => $stockIds, 'trigger' => 'gap_driven'],
                $actorId, $actorName, $key, ['gap_receipt' => $receipt]);
        });
    }

    /** C's validated internal adapter persists the complete receipt in the same canonical A transaction. */
    public function launchWithScheduleReceipt(array $configuration, int $actorId, string $actorName, string $key, array $receipt): BootstrapLaunchResult
    {
        BootstrapRequestContext::validateSchedule($configuration, $actorId, $receipt);
        return $this->launchManaged([...$configuration, 'trigger' => $receipt['trigger']], $actorId, $actorName, $key,
            ['schedule_receipt' => $receipt]);
    }

    public function history(array $filters = []): LengthAwarePaginator
    {
        $page = FundamentalBootstrapRun::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['trigger'] ?? null, fn ($q, $v) => $q->where('trigger', $v))
            ->latest('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
        $page->getCollection()->transform(fn (FundamentalBootstrapRun $run) => $this->presentRun($run));
        return $page;
    }

    public function detail(FundamentalBootstrapRun $run): array
    {
        $jobs = $run->jobs()->with('stock:id,symbol,name')->orderBy('id')->paginate(50);
        $jobs->getCollection()->transform(function (FundamentalBootstrapJob $job): array {
            return [
                'id' => $job->id, 'stock' => ['id' => $job->stock_id, 'symbol' => $job->stock?->symbol, 'name' => $job->stock?->name],
                'status' => $job->status, 'attempts' => $job->attempts,
                'failure_reason' => $job->last_error ? $this->normalizeReason($job->last_error) : null,
                'quarterly_status' => $job->quarterly_status, 'annual_status' => $job->annual_status,
                'coverage' => $this->presentCoverage($job->coverage_json),
                'period' => ['from' => $job->earliest_period?->toDateString(), 'to' => $job->latest_period?->toDateString()],
                'facts' => ['inserted' => $job->facts_inserted, 'upgraded' => $job->facts_upgraded, 'deduped' => $job->facts_deduped, 'rejected' => $job->facts_rejected],
                'updated_at' => $job->updated_at?->toIso8601String(),
            ];
        });
        $current = $run->jobs()->where('status', FundamentalBootstrapJob::STATUS_RUNNING)->with('stock:id,symbol')->first();
        return ['run' => $this->presentRun($run), 'progress' => [
            'requested' => $run->requested, 'queued' => $run->queued, 'running' => $run->running,
            'completed' => $run->completed, 'failed' => $run->failed,
            'pending' => max(0, $run->queued + $run->running),
            'current_item' => $current ? ['stock_id' => $current->stock_id, 'symbol' => $current->stock?->symbol] : null,
            'last_updated_at' => $run->updated_at?->toIso8601String(),
        ], 'jobs' => $jobs];
    }

    private function presentCoverage(?array $coverage): array
    {
        $cadences = [];
        foreach (['quarterly', 'annual'] as $cadence) {
            $stats = $coverage['cadences'][$cadence] ?? null;
            if (! is_array($stats)) continue;
            $providers = is_array($stats['providers'] ?? null) ? $stats['providers'] : [];
            $cadences[$cadence] = [
                'provider_rows' => min(10000, max(0, (int) ($stats['provider_rows'] ?? 0))),
                'accepted_rows' => min(10000, max(0, (int) ($stats['accepted_rows'] ?? 0))),
                'rejected_rows' => min(10000, max(0, (int) ($stats['rejected_rows'] ?? 0))),
                'providers' => array_values(array_intersect(
                    ['yahoo', 'nse_official', 'bse_official'],
                    array_unique(array_filter($providers, 'is_string')),
                )),
            ];
        }
        return [
            'cadences' => $cadences,
            'period_count' => min(1000, max(0, (int) ($coverage['period_count'] ?? 0))),
        ];
    }

    public function retryFailed(FundamentalBootstrapRun $source, int $actorId, string $actorName, string $key): BootstrapLaunchResult
    {
        $ids = $source->jobs()->where('status', FundamentalBootstrapJob::STATUS_FAILED)->pluck('stock_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) throw ValidationException::withMessages(['run' => 'This run has no failed items to retry.']);
        return $this->launch(['scope' => 'stocks', 'stock_ids' => $ids, 'trigger' => 'retry', 'origin_run_id' => $source->id], $actorId, $actorName, $key);
    }

    private function normalizeReason(string $reason): string
    {
        $normalized = mb_strtolower($reason);
        return match (true) {
            str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out') => 'Provider request timed out.',
            str_contains($normalized, 'rate limit') || str_contains($normalized, 'too many requests') => 'Provider rate limit reached.',
            str_contains($normalized, 'unavailable') || str_contains($normalized, 'connection refused') => 'Provider is unavailable.',
            str_contains($normalized, 'invalid') || str_contains($normalized, 'malformed') => 'Provider response was invalid.',
            default => 'Bootstrap item failed; inspect the protected operational logs.',
        };
    }

    public function presentRun(FundamentalBootstrapRun $run): array
    {
        return [
            'id' => $run->id, 'trigger' => $run->trigger, 'scope' => $run->scope, 'status' => $run->status,
            'request' => [
                'scope' => $run->request_snapshot['scope'] ?? $run->scope,
                'stock_count' => (int) ($run->requested ?? 0),
                'forced' => (bool) ($run->request_snapshot['force'] ?? false),
                'reason' => $run->operator_reason,
                'origin_run_id' => $run->request_snapshot['origin_run_id'] ?? null,
                'stock_ids' => array_values(array_filter($run->request_snapshot['stock_ids'] ?? [], fn ($id) => is_int($id) && $id > 0)),
                'schedule' => isset($run->request_snapshot['schedule_receipt']) ? [
                    'id' => $run->request_snapshot['schedule_receipt']['schedule_id'] ?? null,
                    'generation' => $run->request_snapshot['schedule_receipt']['schedule_generation'] ?? null,
                    'version' => $run->request_snapshot['schedule_receipt']['schedule_version'] ?? null,
                    'preset_id' => $run->request_snapshot['schedule_receipt']['preset_id'] ?? null,
                    'preset_revision' => $run->request_snapshot['schedule_receipt']['preset_revision'] ?? null,
                    'scheduled_at' => $run->request_snapshot['schedule_receipt']['scheduled_at'] ?? null,
                    'run_now' => $run->request_snapshot['schedule_receipt']['run_now'] ?? null,
                ] : null,
                'gaps' => isset($run->request_snapshot['gap_receipt']) ? [
                    'ids' => $run->request_snapshot['gap_receipt']['gap_ids'] ?? [],
                    'metric' => $run->request_snapshot['gap_receipt']['metric'] ?? null,
                    'cadence' => $run->request_snapshot['gap_receipt']['cadence'] ?? null,
                    'execution_scope' => 'whole_stock',
                ] : null,
            ],
            'counts' => ['requested' => $run->requested, 'queued' => $run->queued, 'running' => $run->running, 'completed' => $run->completed, 'failed' => $run->failed],
            'failure_reason' => $run->last_error ? $this->normalizeReason($run->last_error) : null,
            'actor' => [
                'id' => $run->request_snapshot['requested_by']['id'] ?? $run->created_by_user_id,
                'name' => $run->request_snapshot['requested_by']['name'] ?? $run->trigger_actor,
            ],
            'created_at' => $run->created_at?->toIso8601String(), 'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(), 'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

}
