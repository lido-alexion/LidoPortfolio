<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class FundamentalBootstrapService
{
    public const PROCESS_LOCK_KEY = 'stox:fundamentals:bootstrap-process';

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalDataProvider $provider,
        protected FundamentalBootstrapAnomalyGuard $anomalyGuard,
        protected FundamentalHistoricalIngestService $historicalIngest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $latest = FundamentalBootstrapRun::query()->latest('id')->first();
        $terminalCounts = FundamentalBootstrapJob::query()
            ->when($latest, fn (Builder $q) => $q->where('run_id', $latest->id))
            ->selectRaw('status, COUNT(*) as count')
            ->whereIn('status', FundamentalBootstrapJob::TERMINAL_STATUSES)
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'latest_run' => $latest?->toArray(),
            'terminal_counts' => $terminalCounts,
            'queued_jobs' => FundamentalBootstrapJob::query()->whereIn('status', ['queued', 'retry'])->count(),
            'running_jobs' => FundamentalBootstrapJob::query()->where('status', 'running')->count(),
        ];
    }

    /**
     * @param  list<int>|null  $stockIds
     * @return array<string, mixed>|FundamentalBootstrapRun
     */
    public function createRun(
        string $scope,
        ?array $stockIds = null,
        ?int $createdByUserId = null,
        bool $dryRun = false,
    ): array|FundamentalBootstrapRun {
        if (in_array($scope, ['stock', 'stocks'], true) && ($stockIds === null || $stockIds === [])) {
            throw new \InvalidArgumentException('No stock matched the requested symbol scope.');
        }

        $stocks = $this->resolveStocks($scope, $stockIds);
        if (in_array($scope, ['stock', 'stocks'], true) && $stocks->isEmpty()) {
            throw new \InvalidArgumentException('No active equity matched the requested stock scope.');
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'scope' => $scope,
                'stock_count' => $stocks->count(),
                'symbols' => $stocks->pluck('symbol')->take(25)->values()->all(),
            ];
        }

        return DB::transaction(function () use ($scope, $stocks, $createdByUserId): FundamentalBootstrapRun {
            $run = FundamentalBootstrapRun::query()->create([
                'trigger' => 'manual',
                'scope' => $scope,
                'status' => 'queued',
                'requested' => $stocks->count(),
                'queued' => $stocks->count(),
                'created_by_user_id' => $createdByUserId,
            ]);

            foreach ($stocks as $stock) {
                FundamentalBootstrapJob::query()->create([
                    'run_id' => $run->id,
                    'stock_id' => $stock->id,
                    'status' => FundamentalBootstrapJob::STATUS_QUEUED,
                ]);
            }

            return $run->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function process(FundamentalBootstrapRun $run, int $batch = 10): array
    {
        $fresh = $run->fresh();
        if (! $fresh || ! in_array($fresh->status, ['queued', 'running'], true)) {
            return $fresh?->toArray() ?? [];
        }

        $lock = Cache::lock(self::PROCESS_LOCK_KEY, 900);
        if (! $lock->get()) {
            return array_merge($run->fresh()->toArray(), [
                'skipped_reason' => 'another_bootstrap_slice_is_in_progress',
            ]);
        }

        try {
            return $this->processLocked($run, $batch);
        } finally {
            $lock->release();
        }
    }

    /**
     * Cancel unfinished jobs under the same process lock used by bootstrap slices.
     * Database row locks remain the durable fence if the cache lock lease expires mid-slice.
     *
     * @return array<string, mixed>
     */
    public function cancelRun(FundamentalBootstrapRun $run, string $actor, string $reason): array
    {
        $actor = trim($actor);
        $reason = trim($reason);
        if ($actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Cancellation requires a non-empty actor and reason.');
        }
        if (mb_strlen($actor) > 255 || mb_strlen($reason) > 2000) {
            throw new \InvalidArgumentException('Cancellation actor must be at most 255 characters and reason at most 2000 characters.');
        }

        $lock = Cache::lock(self::PROCESS_LOCK_KEY, 900);
        if (! $lock->get()) {
            return [
                'run_id' => $run->id,
                'status' => $run->fresh()->status,
                'skipped_reason' => 'another_bootstrap_slice_is_in_progress',
            ];
        }

        try {
            return DB::transaction(function () use ($run, $actor, $reason): array {
                $lockedRun = FundamentalBootstrapRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($lockedRun->status === 'cancelled') {
                    return $lockedRun->toArray();
                }
                if (! in_array($lockedRun->status, ['queued', 'running'], true)) {
                    return $lockedRun->toArray();
                }

                $unfinishedStatuses = [
                    FundamentalBootstrapJob::STATUS_QUEUED,
                    FundamentalBootstrapJob::STATUS_RETRY,
                    FundamentalBootstrapJob::STATUS_RUNNING,
                ];
                $before = $lockedRun->jobs()
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->map(fn ($count): int => (int) $count)
                    ->all();
                $cancelledAt = now();
                $cancelledCount = $lockedRun->jobs()
                    ->whereIn('status', $unfinishedStatuses)
                    ->update([
                        'status' => FundamentalBootstrapJob::STATUS_CANCELLED,
                        'completed_at' => $cancelledAt,
                        'next_attempt_at' => null,
                        'updated_at' => $cancelledAt,
                    ]);

                $this->refreshRunCounters($lockedRun);
                $summary = $lockedRun->summary_json ?? [];
                $summary['cancellation'] = [
                    'actor' => $actor,
                    'reason' => $reason,
                    'cancelled_at' => $cancelledAt->toIso8601String(),
                    'cancelled_jobs' => (int) $cancelledCount,
                    'cancelled_by_previous_status' => collect($before)
                        ->only($unfinishedStatuses)
                        ->all(),
                    'preserved_terminal_jobs' => collect($before)
                        ->except($unfinishedStatuses)
                        ->all(),
                ];

                $lockedRun->forceFill([
                    'status' => 'cancelled',
                    'summary_json' => $summary,
                    'completed_at' => $cancelledAt,
                ])->save();

                return $lockedRun->fresh()->toArray();
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function processLocked(FundamentalBootstrapRun $run, int $batch): array
    {
        $started = DB::transaction(function () use ($run): bool {
            $lockedRun = FundamentalBootstrapRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $lockedRun || ! in_array($lockedRun->status, ['queued', 'running'], true)) {
                return false;
            }
            $lockedRun->forceFill([
                'status' => 'running',
                'started_at' => $lockedRun->started_at ?? now(),
            ])->save();

            return true;
        });
        if (! $started) {
            return $run->fresh()?->toArray() ?? [];
        }

        $settings = $this->fundamentals->settings();

        $jobs = FundamentalBootstrapJob::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [FundamentalBootstrapJob::STATUS_QUEUED, FundamentalBootstrapJob::STATUS_RETRY])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min($batch, 100)))
            ->get();

        foreach ($jobs as $job) {
            $claimedJob = $this->claimJob($run->id, $job->id);
            if (! $claimedJob) {
                if (! in_array($run->fresh()->status, ['queued', 'running'], true)) {
                    break;
                }
                continue;
            }

            try {
                $stock = Stock::query()->findOrFail($claimedJob->stock_id);
                $this->bootstrapStock($claimedJob, $stock);
            } catch (Throwable $error) {
                $this->recordJobFailure($run->id, $claimedJob->id, $claimedJob->attempts, $settings->max_attempts, $error->getMessage());
            }

            if ($settings->request_delay_ms > 0) {
                usleep($settings->request_delay_ms * 1000);
            }
        }

        $this->finishRunSlice($run->id);

        return $run->fresh()?->toArray() ?? [];
    }

    protected function bootstrapStock(FundamentalBootstrapJob $job, Stock $stock): bool
    {
        if (! $this->isJobActive($job->run_id, $job->id)) {
            return false;
        }

        if ($stock->is_benchmark) {
            return $this->finishJob($job->run_id, $job->id, [
                'status' => FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA,
                'completed_at' => now(),
                'next_attempt_at' => null,
                'last_error' => 'benchmark instruments are excluded from bootstrap',
            ]);
        }

        $totals = [
            'inserted' => 0,
            'restated' => 0,
            'deduped' => 0,
            'rejected' => 0,
        ];
        $periodEnds = [];
        $cadenceStats = [];

        foreach ([FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL] as $cadence) {
            if (! $this->isJobActive($job->run_id, $job->id)) {
                return false;
            }
            $rows = $this->historicalIngest->fetch($stock, $cadence);
            $accepted = [];
            $rejected = 0;

            foreach ($rows as $row) {
                if (! $this->anomalyGuard->accepts($row)) {
                    $rejected++;
                    continue;
                }
                $accepted[] = $row;
                $periodEnds[] = (string) $row['period_end'];
            }

            $storeStats = $this->withActiveJob($job->run_id, $job->id, function () use ($stock, $cadence, $accepted, $rows): array {
                $stats = $accepted === []
                    ? ['inserted' => 0, 'deduped' => 0, 'restated' => 0]
                    : $this->fundamentals->storeFacts($stock, $accepted);

                if ($accepted !== []) {
                    $providers = collect($accepted)->pluck('provider')->filter()->unique()->values()->all();
                    $provider = count($providers) === 1 ? (string) $providers[0] : 'hybrid';
                    $responseHash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $this->fundamentals->recordSuccessfulProviderCheck(
                        $stock,
                        $cadence,
                        now(),
                        $provider,
                        $responseHash,
                        null,
                        [
                            'transport' => 'historical_ingest',
                            'check_kind' => 'merged_source_rows',
                            'providers' => $providers,
                            'row_count' => count($rows),
                        ],
                    );
                }

                return $stats;
            });
            if ($storeStats === null) {
                return false;
            }

            $totals['inserted'] += (int) ($storeStats['inserted'] ?? 0);
            $totals['restated'] += (int) ($storeStats['restated'] ?? 0);
            $totals['deduped'] += (int) ($storeStats['deduped'] ?? 0);
            $totals['rejected'] += $rejected;

            $cadenceStats[$cadence] = [
                'provider_rows' => count($rows),
                'accepted_rows' => count($accepted),
                'rejected_rows' => $rejected,
            ];
        }

        $earliest = $this->minDate($periodEnds);
        $latest = $this->maxDate($periodEnds);
        $hasData = $totals['inserted'] + $totals['restated'] + $totals['deduped'] > 0;

        $status = FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA;
        if (! $hasData && $totals['rejected'] > 0) {
            $status = FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL;
        } elseif ($hasData && $totals['rejected'] > 0) {
            $status = FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL;
        } elseif ($hasData) {
            $status = FundamentalBootstrapJob::STATUS_COMPLETE_GOOD;
        }

        return $this->finishJob($job->run_id, $job->id, [
            'status' => $status,
            'completed_at' => now(),
            'next_attempt_at' => null,
            'last_error' => null,
            'quarterly_status' => $this->cadenceQuality($cadenceStats[FundamentalDataService::CADENCE_QUARTERLY] ?? []),
            'annual_status' => $this->cadenceQuality($cadenceStats[FundamentalDataService::CADENCE_ANNUAL] ?? []),
            'earliest_period' => $earliest,
            'latest_period' => $latest,
            'facts_inserted' => $totals['inserted'],
            'facts_upgraded' => $totals['restated'],
            'facts_deduped' => $totals['deduped'],
            'facts_rejected' => $totals['rejected'],
            'anomalies_count' => $totals['rejected'],
            'coverage_json' => [
                'cadences' => $cadenceStats,
                'period_count' => count(array_unique($periodEnds)),
            ],
        ]);
    }

    protected function claimJob(int $runId, int $jobId): ?FundamentalBootstrapJob
    {
        return DB::transaction(function () use ($runId, $jobId): ?FundamentalBootstrapJob {
            $run = FundamentalBootstrapRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'running') {
                return null;
            }

            $job = FundamentalBootstrapJob::query()->whereKey($jobId)->where('run_id', $runId)->lockForUpdate()->first();
            if (! $job || ! in_array($job->status, [FundamentalBootstrapJob::STATUS_QUEUED, FundamentalBootstrapJob::STATUS_RETRY], true)) {
                return null;
            }

            if ($job->next_attempt_at !== null && $job->next_attempt_at->isFuture()) {
                return null;
            }

            $job->forceFill([
                'status' => FundamentalBootstrapJob::STATUS_RUNNING,
                'attempts' => $job->attempts + 1,
                'started_at' => $job->started_at ?? now(),
            ])->save();

            return $job->fresh();
        });
    }

    protected function isJobActive(int $runId, int $jobId): bool
    {
        return $this->withActiveJob(
            $runId,
            $jobId,
            fn (FundamentalBootstrapJob $activeJob, FundamentalBootstrapRun $activeRun): bool => true,
        ) === true;
    }

    /**
     * Run and job locks serialize every durable worker write against cancellation.
     * Provider I/O stays outside this transaction; stale workers fail closed here.
     */
    protected function withActiveJob(int $runId, int $jobId, callable $callback): mixed
    {
        return DB::transaction(function () use ($runId, $jobId, $callback): mixed {
            $run = FundamentalBootstrapRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'running') {
                return null;
            }

            $job = FundamentalBootstrapJob::query()->whereKey($jobId)->where('run_id', $runId)->lockForUpdate()->first();
            if (! $job || $job->status !== FundamentalBootstrapJob::STATUS_RUNNING) {
                return null;
            }

            return $callback($job, $run);
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function finishJob(int $runId, int $jobId, array $attributes): bool
    {
        return $this->withActiveJob($runId, $jobId, function (FundamentalBootstrapJob $job) use ($attributes): bool {
            $job->forceFill($attributes)->save();

            return true;
        }) === true;
    }

    protected function recordJobFailure(int $runId, int $jobId, int $attempts, int $maxAttempts, string $error): void
    {
        $this->withActiveJob($runId, $jobId, function (FundamentalBootstrapJob $job, FundamentalBootstrapRun $run) use ($attempts, $maxAttempts, $error): void {
            $willRetry = $attempts < $maxAttempts;
            $job->forceFill([
                'status' => $willRetry ? FundamentalBootstrapJob::STATUS_RETRY : FundamentalBootstrapJob::STATUS_FAILED,
                'completed_at' => $willRetry ? null : now(),
                'next_attempt_at' => $willRetry ? now()->addMinutes(min(60, 2 ** max(0, $attempts - 1))) : null,
                'last_error' => $error,
            ])->save();
            $run->forceFill(['last_error' => $error])->save();
        });
    }

    protected function finishRunSlice(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = FundamentalBootstrapRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'running') {
                return;
            }

            $this->refreshRunCounters($run);
            $remaining = FundamentalBootstrapJob::query()
                ->where('run_id', $run->id)
                ->whereIn('status', [
                    FundamentalBootstrapJob::STATUS_QUEUED,
                    FundamentalBootstrapJob::STATUS_RETRY,
                    FundamentalBootstrapJob::STATUS_RUNNING,
                ])
                ->count();

            if ($remaining === 0) {
                $run->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'summary_json' => $this->buildRunSummary($run),
                ])->save();
            }
        });
    }

    /**
     * @param  list<int>|null  $stockIds
     * @return Collection<int, Stock>
     */
    protected function resolveStocks(string $scope, ?array $stockIds): Collection
    {
        if ($scope === 'rerun_failed') {
            return $this->stocksFromLatestTerminalJobs([FundamentalBootstrapJob::STATUS_FAILED]);
        }

        if ($scope === 'rerun_partial') {
            return $this->stocksFromLatestTerminalJobs([FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL]);
        }

        $query = Stock::query()
            ->effectivelyActive()
            ->where(function ($stockQuery): void {
                $stockQuery->where('is_benchmark', false)->orWhereNull('is_benchmark');
            })
            ->orderBy('id');

        if ($stockIds !== null && $stockIds !== []) {
            $query->whereIn('id', $stockIds);
        }

        return $query->get();
    }

    /**
     * @param  list<string>  $symbols
     * @return list<int>
     */
    public function stockIdsFromSymbols(array $symbols): array
    {
        $normalized = collect($symbols)
            ->map(fn ($s) => strtoupper(trim((string) $s)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return [];
        }

        return Stock::query()
            ->whereIn('symbol', $normalized)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $statuses
     * @return Collection<int, Stock>
     */
    protected function stocksFromLatestTerminalJobs(array $statuses): Collection
    {
        $latestRunId = FundamentalBootstrapRun::query()->latest('id')->value('id');
        if ($latestRunId === null) {
            return collect();
        }

        $stockIds = FundamentalBootstrapJob::query()
            ->where('run_id', $latestRunId)
            ->whereIn('status', $statuses)
            ->pluck('stock_id');

        return Stock::query()->whereIn('id', $stockIds)->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $cadence
     */
    protected function cadenceQuality(array $cadence): string
    {
        $accepted = (int) ($cadence['accepted_rows'] ?? 0);
        $rejected = (int) ($cadence['rejected_rows'] ?? 0);
        $provider = (int) ($cadence['provider_rows'] ?? 0);

        if ($provider === 0) {
            return 'no_data';
        }
        if ($accepted === 0) {
            return 'rejected';
        }
        if ($rejected > 0) {
            return 'partial';
        }

        return 'good';
    }

    /**
     * @param  list<string>  $dates
     */
    protected function minDate(array $dates): ?string
    {
        $parsed = collect($dates)
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->sort()
            ->first();

        return $parsed?->toDateString();
    }

    /**
     * @param  list<string>  $dates
     */
    protected function maxDate(array $dates): ?string
    {
        $parsed = collect($dates)
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->sortDesc()
            ->first();

        return $parsed?->toDateString();
    }

    protected function refreshRunCounters(FundamentalBootstrapRun $run): void
    {
        $counts = $run->jobs()
            ->selectRaw("
                COUNT(*) as requested,
                SUM(status IN ('queued', 'retry')) as queued,
                SUM(status = 'running') as running,
                SUM(status IN ('complete_good', 'complete_partial', 'complete_no_data')) as completed,
                SUM(status = 'failed') as failed
            ")
            ->first();

        $run->forceFill([
            'requested' => (int) ($counts->requested ?? 0),
            'queued' => (int) ($counts->queued ?? 0),
            'running' => (int) ($counts->running ?? 0),
            'completed' => (int) ($counts->completed ?? 0),
            'failed' => (int) ($counts->failed ?? 0),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRunSummary(FundamentalBootstrapRun $run): array
    {
        return $run->jobs()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }
}
