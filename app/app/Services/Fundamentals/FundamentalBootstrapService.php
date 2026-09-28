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
        $stocks = $this->resolveStocks($scope, $stockIds);

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
     * @return array<string, mixed>
     */
    protected function processLocked(FundamentalBootstrapRun $run, int $batch): array
    {
        $settings = $this->fundamentals->settings();
        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
        ])->save();

        $jobs = FundamentalBootstrapJob::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [FundamentalBootstrapJob::STATUS_QUEUED, FundamentalBootstrapJob::STATUS_RETRY])
            ->orderBy('id')
            ->limit(max(1, min($batch, 100)))
            ->get();

        foreach ($jobs as $job) {
            $job->forceFill([
                'status' => FundamentalBootstrapJob::STATUS_RUNNING,
                'attempts' => $job->attempts + 1,
                'started_at' => $job->started_at ?? now(),
            ])->save();

            try {
                $stock = Stock::query()->findOrFail($job->stock_id);
                $this->bootstrapStock($job, $stock);
            } catch (Throwable $error) {
                $willRetry = $job->attempts < $settings->max_attempts;
                $job->forceFill([
                    'status' => $willRetry ? FundamentalBootstrapJob::STATUS_RETRY : FundamentalBootstrapJob::STATUS_FAILED,
                    'completed_at' => $willRetry ? null : now(),
                    'last_error' => $error->getMessage(),
                ])->save();
                $run->forceFill(['last_error' => $error->getMessage()])->save();
            }

            if ($settings->request_delay_ms > 0) {
                usleep($settings->request_delay_ms * 1000);
            }
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

        return $run->fresh()->toArray();
    }

    public function bootstrapStock(FundamentalBootstrapJob $job, Stock $stock): void
    {
        if ($stock->is_benchmark) {
            $job->forceFill([
                'status' => FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA,
                'completed_at' => now(),
                'last_error' => 'benchmark instruments are excluded from bootstrap',
            ])->save();

            return;
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

            $storeStats = $accepted === []
                ? ['inserted' => 0, 'deduped' => 0, 'restated' => 0]
                : $this->fundamentals->storeFacts($stock, $accepted);

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

        $job->forceFill([
            'status' => $status,
            'completed_at' => now(),
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
        ])->save();
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
