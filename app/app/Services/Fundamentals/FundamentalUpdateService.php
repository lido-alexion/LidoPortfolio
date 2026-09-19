<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\AdminOperationalAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class FundamentalUpdateService
{
    public const SCHEDULE_LOCK_KEY = 'stox:fundamentals:scheduled-slice';

    public const PROCESS_LOCK_KEY = 'stox:fundamentals:process-slice';

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalDataProvider $provider,
        protected AdminOperationalAlertService $opsAlerts,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $settings = $this->fundamentals->settings();
        $latest = FundamentalUpdateRun::query()->latest('id')->first();
        $coverage = DB::table('stox_fundamental_facts')
            ->selectRaw('cadence, count(distinct stock_id) as stocks, max(period_end) as latest_period_end, max(availability_date) as latest_availability_date')
            ->groupBy('cadence')
            ->get()
            ->keyBy('cadence')
            ->map(fn ($row) => [
                'stocks' => (int) $row->stocks,
                'latest_period_end' => $row->latest_period_end,
                'latest_availability_date' => $row->latest_availability_date,
            ]);

        return [
            'settings' => $settings->toArray(),
            'latest_run' => $latest?->toArray(),
            'coverage' => [
                FundamentalDataService::CADENCE_QUARTERLY => $coverage->get(FundamentalDataService::CADENCE_QUARTERLY, ['stocks' => 0]),
                FundamentalDataService::CADENCE_ANNUAL => $coverage->get(FundamentalDataService::CADENCE_ANNUAL, ['stocks' => 0]),
            ],
            'queued_jobs' => FundamentalUpdateJob::query()->whereIn('status', ['queued', 'retry'])->count(),
            'running' => FundamentalUpdateRun::query()->where('status', 'running')->exists(),
        ];
    }

    public function createRun(string $trigger = 'manual', string $scope = 'incremental', ?int $stockId = null, int $limit = 25): FundamentalUpdateRun
    {
        $query = Stock::query()->effectivelyActive()->orderBy('id');
        if ($stockId !== null) {
            $query->whereKey($stockId);
        } else {
            $query->limit(max(1, min($limit, 200)));
        }

        return DB::transaction(function () use ($trigger, $scope, $query): FundamentalUpdateRun {
            $run = FundamentalUpdateRun::query()->create([
                'trigger' => $trigger,
                'scope' => $scope,
                'status' => 'queued',
            ]);

            $requested = 0;
            foreach ($query->get(['id']) as $stock) {
                foreach ([FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL] as $cadence) {
                    if ($scope === 'incremental' && FundamentalUpdateJob::query()
                        ->where('stock_id', $stock->id)
                        ->where('cadence', $cadence)
                        ->whereIn('status', ['queued', 'retry', 'running'])
                        ->whereHas('run', function ($runQuery): void {
                            $runQuery->where('scope', 'incremental')->whereIn('status', ['queued', 'running']);
                        })
                        ->exists()) {
                        continue;
                    }

                    FundamentalUpdateJob::query()->firstOrCreate([
                        'run_id' => $run->id,
                        'stock_id' => $stock->id,
                        'cadence' => $cadence,
                    ], [
                        'status' => 'queued',
                        'priority_tier' => 8,
                    ]);
                    $requested++;
                }
            }

            $run->forceFill(['requested' => $requested])->save();

            return $run->fresh();
        });
    }

    /**
     * Resume the durable scheduled backlog before creating new incremental work.
     *
     * @return array<string,mixed>
     */
    public function processScheduledIncremental(int $batch = 25): array
    {
        $lock = Cache::lock(self::SCHEDULE_LOCK_KEY, 900);
        if (! $lock->get()) {
            return [
                'status' => 'skipped',
                'reason' => 'another_scheduled_slice_is_in_progress',
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        try {
            $processLock = Cache::lock(self::PROCESS_LOCK_KEY, 900);
            if (! $processLock->get()) {
                return [
                    'status' => 'skipped',
                    'reason' => 'another_fundamentals_slice_is_in_progress',
                    'processed' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                ];
            }

            try {
                $settings = $this->fundamentals->settings();
                $this->reconcileBacklogLocked((int) $settings->max_attempts);

                $run = FundamentalUpdateRun::query()
                    ->where('scope', 'incremental')
                    ->whereIn('status', ['queued', 'running'])
                    ->whereHas('jobs', function ($query): void {
                        $query->whereIn('status', ['queued', 'retry', 'running']);
                    })
                    ->oldest('id')
                    ->first();

                if ($run === null) {
                    $run = $this->createRun('scheduled', 'incremental', null, $batch);
                }

                return $this->processLocked($run, $batch);
            } finally {
                $processLock->release();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Normalize abandoned/exhausted jobs and finalize runs whose children are terminal.
     *
     * @return array{exhausted:int,recovered:int,superseded:int,finalized:int}
     */
    public function reconcileBacklog(int $maxAttempts = 3): array
    {
        $lock = Cache::lock(self::PROCESS_LOCK_KEY, 900);
        if (! $lock->get()) {
            return ['exhausted' => 0, 'recovered' => 0, 'superseded' => 0, 'finalized' => 0];
        }

        try {
            return $this->reconcileBacklogLocked($maxAttempts);
        } finally {
            $lock->release();
        }
    }

    /** @return array{exhausted:int,recovered:int,superseded:int,finalized:int} */
    private function reconcileBacklogLocked(int $maxAttempts): array
    {
        $exhausted = FundamentalUpdateJob::query()
            ->where('status', 'retry')
            ->where('attempts', '>=', $maxAttempts)
            ->update([
                'status' => 'failed',
                'next_attempt_at' => null,
                'last_error' => DB::raw("CONCAT(COALESCE(last_error, ''), ' [retry budget exhausted; finalized by backlog reconciliation]')"),
            ]);

        $recovered = FundamentalUpdateJob::query()
            ->where('status', 'running')
            ->whereNotNull('last_attempted_at')
            ->where('last_attempted_at', '<', now()->subMinutes(30))
            ->update([
                'status' => 'retry',
                'next_attempt_at' => now(),
                'last_error' => DB::raw("CONCAT(COALESCE(last_error, ''), ' [stale running job recovered]')"),
            ]);

        $superseded = $this->compactDuplicateIncrementalJobs($maxAttempts);
        $finalized = 0;
        FundamentalUpdateRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereDoesntHave('jobs', function ($query): void {
                $query->whereIn('status', ['queued', 'retry', 'running']);
            })
            ->each(function (FundamentalUpdateRun $run) use (&$finalized): void {
                $this->refreshRunCounters($run);
                $run->forceFill([
                    'status' => $run->failed > 0 ? 'completed_with_errors' : 'completed',
                    'completed_at' => $run->completed_at ?? now(),
                ])->save();
                $finalized++;
            });

        return compact('exhausted', 'recovered', 'superseded', 'finalized');
    }

    /**
     * Retain one newest viable job for each stock/cadence and preserve the rest as evidence.
     * Only unfinished incremental work is eligible; targeted/manual runs are never touched.
     */
    private function compactDuplicateIncrementalJobs(int $maxAttempts): int
    {
        return DB::transaction(function () use ($maxAttempts): int {
            $duplicateIdentities = FundamentalUpdateJob::query()
                ->select(['stock_id', 'cadence'])
                ->whereIn('status', ['queued', 'retry', 'running'])
                ->whereHas('run', function ($query): void {
                    $query->where('scope', 'incremental')->whereIn('status', ['queued', 'running']);
                })
                ->groupBy('stock_id', 'cadence')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            $superseded = 0;
            foreach ($duplicateIdentities as $identity) {
                $jobs = FundamentalUpdateJob::query()
                    ->with('run:id,created_at')
                    ->where('stock_id', $identity->stock_id)
                    ->where('cadence', $identity->cadence)
                    ->whereIn('status', ['queued', 'retry', 'running'])
                    ->whereHas('run', function ($query): void {
                        $query->where('scope', 'incremental')->whereIn('status', ['queued', 'running']);
                    })
                    ->lockForUpdate()
                    ->get();

                $authoritative = $jobs
                    ->filter(fn (FundamentalUpdateJob $job): bool => $job->attempts < $maxAttempts)
                    ->sortByDesc(fn (FundamentalUpdateJob $job): string => $this->jobRetentionKey($job))
                    ->first()
                    ?? $jobs->sortByDesc(fn (FundamentalUpdateJob $job): string => $this->jobRetentionKey($job))->first();

                if ($authoritative === null) {
                    continue;
                }

                foreach ($jobs as $job) {
                    if ($job->is($authoritative)) {
                        continue;
                    }

                    $reason = 'Superseded during duplicate incremental backlog reconciliation';
                    $job->forceFill([
                        'status' => 'superseded',
                        'next_attempt_at' => null,
                        'last_error' => trim(($job->last_error ? $job->last_error.' ' : '').'['.$reason.']'),
                    ])->save();
                    $superseded++;
                }
            }

            return $superseded;
        });
    }

    private function jobRetentionKey(FundamentalUpdateJob $job): string
    {
        $createdAt = $job->run?->created_at?->format('Y-m-d H:i:s.u') ?? '';

        return sprintf('%s|%010d|%010d', $createdAt, $job->run_id, $job->id);
    }

    /**
     * @return array<string,mixed>
     */
    public function process(FundamentalUpdateRun $run, int $batch = 25): array
    {
        $lock = Cache::lock(self::PROCESS_LOCK_KEY, 900);
        if (! $lock->get()) {
            return array_merge($run->fresh()->toArray(), [
                'skipped_reason' => 'another_fundamentals_slice_is_in_progress',
            ]);
        }

        try {
            return $this->processLocked($run, $batch);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private function processLocked(FundamentalUpdateRun $run, int $batch = 25): array
    {
        $settings = $this->fundamentals->settings();
        if ($settings->paused) {
            $run->forceFill(['status' => 'paused'])->save();

            return ['status' => 'paused', 'processed' => 0];
        }

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        $sliceError = null;
        $jobs = FundamentalUpdateJob::query()
            ->where('run_id', $run->id)
            ->whereIn('status', ['queued', 'retry'])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('priority_tier')
            ->orderBy('id')
            ->limit(max(1, min($batch, 200)))
            ->get();

        foreach ($jobs as $job) {
            $job->forceFill([
                'status' => 'running',
                'attempts' => $job->attempts + 1,
                'last_attempted_at' => now(),
            ])->save();

            try {
                $stock = Stock::query()->findOrFail($job->stock_id);
                if (! $this->needsFetch($stock, $job->cadence)) {
                    $job->forceFill(['status' => 'skipped'])->save();
                    continue;
                }

                $rows = $this->provider->fetch($stock, $job->cadence);
                $stats = $this->fundamentals->storeFacts($stock, $rows);
                $job->forceFill(['status' => 'completed', 'last_error' => null])->save();
                $run->forceFill(['stats_json' => $this->mergeStats($run->stats_json ?? [], $stats)])->save();
            } catch (Throwable $error) {
                $sliceError = $error->getMessage();
                $willRetry = $job->attempts < $settings->max_attempts;
                $job->forceFill([
                    'status' => $willRetry ? 'retry' : 'failed',
                    'next_attempt_at' => $willRetry ? now()->addMinutes((int) pow(2, max(0, $job->attempts - 1))) : null,
                    'last_error' => $error->getMessage(),
                ])->save();
                $run->forceFill(['last_error' => $error->getMessage()])->save();
            }

            if ($settings->request_delay_ms > 0) {
                usleep($settings->request_delay_ms * 1000);
            }
        }

        $this->refreshRunCounters($run);
        $remaining = FundamentalUpdateJob::query()
            ->where('run_id', $run->id)
            ->whereIn('status', ['queued', 'retry', 'running'])
            ->count();
        if ($remaining === 0) {
            $run->forceFill([
                'status' => $run->failed > 0 ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
            ])->save();
        }

        if ($sliceError !== null) {
            $this->opsAlerts->recordUnattendedFailure(
                AdminOperationalAlertService::KEY_FUNDAMENTALS_UPDATE_FAILED,
                'Fundamentals update failed',
                sprintf('V7 fundamentals run #%d encountered a provider/update failure: %s', $run->id, $sliceError),
                ['run_id' => $run->id, 'status' => $run->status],
            );
            $this->opsAlerts->syncAndNotify();
        } elseif ($run->fresh()->status === 'completed') {
            if ($this->opsAlerts->clearUnattendedFailure(AdminOperationalAlertService::KEY_FUNDAMENTALS_UPDATE_FAILED)) {
                $this->opsAlerts->syncAndNotify();
            }
        }

        return $run->fresh()->toArray();
    }

    private function refreshRunCounters(FundamentalUpdateRun $run): void
    {
        $counts = $run->jobs()
            ->selectRaw("COUNT(*) as requested, SUM(status IN ('completed', 'failed', 'skipped', 'superseded')) as processed, SUM(status = 'completed') as succeeded, SUM(status = 'failed') as failed, SUM(status IN ('skipped', 'superseded')) as skipped")
            ->first();

        $run->forceFill([
            'requested' => (int) ($counts->requested ?? 0),
            'processed' => (int) ($counts->processed ?? 0),
            'succeeded' => (int) ($counts->succeeded ?? 0),
            'failed' => (int) ($counts->failed ?? 0),
            'skipped' => (int) ($counts->skipped ?? 0),
        ])->save();
    }

    private function needsFetch(Stock $stock, string $cadence): bool
    {
        $latest = DB::table('stox_fundamental_facts')
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->max('first_fetched_at');

        if ($latest === null) {
            return true;
        }

        $hours = $cadence === FundamentalDataService::CADENCE_ANNUAL ? 24 * 14 : 24 * 3;

        return now()->diffInHours(\Carbon\Carbon::parse($latest)) >= $hours;
    }

    /** @param array<string,mixed> $current @param array<string,int> $stats */
    private function mergeStats(array $current, array $stats): array
    {
        foreach ($stats as $key => $value) {
            $current[$key] = (int) ($current[$key] ?? 0) + (int) $value;
        }

        return $current;
    }
}
