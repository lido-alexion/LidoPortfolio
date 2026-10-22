<?php

namespace App\Services\Fundamentals;

use App\Models\V7\BootstrapScheduleOccurrence;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\Scheduling\BootstrapScheduleLaunchAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Bounded, restartable producer for the already-authorized OPS001 command. */
class BootstrapOperationsProducer
{
    public function __construct(
        private BootstrapScheduleOperationsService $schedules,
        private BootstrapScheduleLaunchAdapter $scheduleAdapter,
        private FundamentalBootstrapService $engine,
        private BootstrapCoverageOperationsService $coverage,
        private BootstrapOperationalOutcomeService $outcomes,
    ) {}

    /** @return array<string,mixed> */
    public function tick(int $batch = 10): array
    {
        $batch = min(100, max(1, $batch));
        $materialized = $this->schedules->materializeDue(CarbonImmutable::now('UTC'), 100);
        $delivered = $this->schedules->deliverPending($this->scheduleAdapter, 50);
        $processed = 0;
        $run = FundamentalBootstrapRun::query()->opsManaged()
            ->whereIn('status', ['queued', 'running'])->orderBy('id')->first();
        $engineSlice = null;
        if ($run && ! $this->legacyRunIsActive()) {
            // The engine rechecks global pause, global ownership and the process
            // fence immediately before claiming any provider work.
            $engineSlice = $this->engine->process($run, $batch);
            $processed = isset($engineSlice['skipped_reason']) ? 0 : 1;
        }

        $reconciledRuns = 0;
        foreach (FundamentalBootstrapRun::query()->opsManaged()
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('stox_bootstrap_projection_receipts as projection_receipt')
                    ->whereColumn('projection_receipt.run_id', 'stox_fundamental_bootstrap_runs.id')
                    ->whereNotNull('projection_receipt.outcomes_completed_at');
            })->orderBy('id')->limit(2)->get() as $terminal) {
            try {
                if ($this->reconcileRun($terminal->id)) $reconciledRuns++;
            } catch (Throwable $error) {
                Log::warning('OPS001 projection reconciliation deferred', [
                    'run_id' => (int) $terminal->id,
                    'error_code' => 'projection_reconcile_failed',
                ]);
            }
        }

        $occurrences = $this->notifyScheduledOccurrences(50);
        return ['materialized' => $materialized, 'delivered' => $delivered, 'processed' => $processed, 'engine_slice' => $engineSlice,
            'reconciled_runs' => $reconciledRuns, 'occurrence_outcomes' => $occurrences];
    }

    private function legacyRunIsActive(): bool
    {
        return FundamentalBootstrapRun::query()->where('status', 'running')
            ->whereNotIn('id', FundamentalBootstrapRun::query()->opsManaged()->select('id'))->exists();
    }

    /** Reconcile one page and advance its cursor atomically with B's persistence. */
    private function reconcileRun(int $runId): bool
    {
        return DB::transaction(function () use ($runId): bool {
            $run = FundamentalBootstrapRun::query()->opsManaged()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) return false;
            $receipt = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $runId)->lockForUpdate()->first();
            if (! $receipt) {
                DB::table('stox_bootstrap_projection_receipts')->insertOrIgnore([
                    'run_id' => $runId, 'last_job_id' => 0, 'reconciliation_completed_at' => null,
                    'outcomes_completed_at' => null, 'outcomes_json' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $receipt = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $runId)->lockForUpdate()->first();
            }
            if ($receipt->reconciliation_completed_at === null) {
                $ids = FundamentalBootstrapJob::query()->where('run_id', $runId)->where('id', '>', (int) $receipt->last_job_id)
                    ->orderBy('id')->limit(50)->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($ids !== []) {
                    // This B-owned method must reconcile exactly the supplied IDs
                    // and persist through its existing implementation only.
                    $this->coverage->reconcileJobs($run, $ids);
                    DB::table('stox_bootstrap_projection_receipts')->where('run_id', $runId)->update([
                        'last_job_id' => max($ids), 'updated_at' => now(),
                    ]);
                    return true;
                }
                DB::table('stox_bootstrap_projection_receipts')->where('run_id', $runId)->update([
                    'reconciliation_completed_at' => now(), 'updated_at' => now(),
                ]);
                $receipt->reconciliation_completed_at = now();
            }
            if ($receipt->outcomes_completed_at !== null) return false;
            $accepted = [];
            $accepted['run_outcome'] = $this->outcomes->notifyRunOutcome($run);
            foreach ([BootstrapWarningCategory::REJECTED_FACTS, BootstrapWarningCategory::ANOMALY_REVIEW,
                BootstrapWarningCategory::MISSING_PROVENANCE] as $category) {
                $accepted[$category] = $this->outcomes->notifyRepeatedQualityWarningOccurrences($run, $category);
            }
            DB::table('stox_bootstrap_projection_receipts')->where('run_id', $runId)->update([
                'outcomes_json' => json_encode($accepted, JSON_THROW_ON_ERROR),
                'outcomes_completed_at' => now(), 'updated_at' => now(),
            ]);
            return true;
        }, 3);
    }

    /** Scan bounded actionable rows; D validates receipt identity and outcome eligibility. */
    private function notifyScheduledOccurrences(int $limit): int
    {
        $rows = BootstrapScheduleOccurrence::query()->where(function ($query): void {
            $query->where(function ($q): void { $q->where('status', 'queued')->whereNotNull('run_id')->whereNotNull('delivered_at'); })
                ->orWhere(function ($q): void { $q->where('status', 'cannot_start')->whereNotNull('failed_at')->whereNull('run_id'); });
        })->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('stox_bootstrap_outcome_receipts as accepted')
                ->where(function ($keys): void {
                    $keys->whereRaw("accepted.event_key = CONCAT('occurrence:', stox_bootstrap_schedule_occurrences.id, ':material_delay')")
                        ->orWhereRaw("accepted.event_key = CONCAT('occurrence:', stox_bootstrap_schedule_occurrences.id, ':cannot_start')");
                });
        })->leftJoin('stox_bootstrap_occurrence_projection_checks as scan_check',
            'scan_check.occurrence_id', '=', 'stox_bootstrap_schedule_occurrences.id')
            ->select('stox_bootstrap_schedule_occurrences.*', DB::raw('COALESCE(scan_check.scan_count, 0) as scan_count'))
            ->orderBy('scan_count')->orderBy('stox_bootstrap_schedule_occurrences.id')->limit($limit)->get();
        $count = 0;
        foreach ($rows as $occurrence) {
            $hasReceipt = DB::table('stox_bootstrap_outcome_receipts')->whereIn('event_key', [
                'occurrence:'.$occurrence->id.':material_delay', 'occurrence:'.$occurrence->id.':cannot_start',
            ])->exists();
            if ($hasReceipt) {
                $this->recordOccurrenceCheck((int) $occurrence->id);
                continue;
            }
            try {
                $outcome = $occurrence->status === 'cannot_start' ? 'cannot_start' : 'started';
                if ($this->outcomes->notifyScheduledOccurrence((int) $occurrence->id, $outcome)) $count++;
            } catch (Throwable) {
                Log::warning('OPS001 schedule outcome deferred', ['occurrence_id' => (int) $occurrence->id, 'error_code' => 'outcome_publish_failed']);
            } finally {
                $this->recordOccurrenceCheck((int) $occurrence->id);
            }
        }
        return $count;
    }

    private function recordOccurrenceCheck(int $occurrenceId): void
    {
        DB::transaction(function () use ($occurrenceId): void {
            DB::table('stox_bootstrap_occurrence_projection_checks')->insertOrIgnore([
                'occurrence_id' => $occurrenceId, 'scan_count' => 0, 'last_checked_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $check = DB::table('stox_bootstrap_occurrence_projection_checks')->where('occurrence_id', $occurrenceId)->lockForUpdate()->first();
            DB::table('stox_bootstrap_occurrence_projection_checks')->where('occurrence_id', $occurrenceId)->update([
                'scan_count' => (int) $check->scan_count + 1, 'last_checked_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }
}
