<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\AdminOperationalAlertService;
use App\Services\Nifty500ConstituentService;
use App\Services\Fundamentals\Historical\ExchangeRequestDeferred;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class FundamentalUpdateService
{
    public const SCHEDULE_LOCK_KEY = 'stox:fundamentals:scheduled-slice';

    public const PROCESS_LOCK_KEY = 'stox:fundamentals:process-slice';

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalHistoricalIngestService $historicalIngest,
        protected AdminOperationalAlertService $opsAlerts,
        private readonly ?Nifty500ConstituentService $nifty500 = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $settings = $this->fundamentals->settings();
        $latest = FundamentalUpdateRun::query()->latest('id')->first();
        $health = app(FundamentalPipelineHealth::class)->recent();
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
            'pipeline_health' => $health,
        ];
    }

    public function createRun(string $trigger = 'manual', string $scope = 'incremental', ?int $stockId = null, int $limit = 25): FundamentalUpdateRun
    {
        $settings = $this->fundamentals->settings();
        $query = Stock::query()
            ->effectivelyActive()
            ->where(function ($stockQuery): void {
                $stockQuery->where('is_benchmark', false)->orWhereNull('is_benchmark');
            });
        if ($stockId === null) {
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')->from('stox_yahoo_fundamental_symbol_state as ys')->whereColumn('ys.stock_id', 'portfolio_stocks.id')->whereNotNull('ys.skipped_at');
            })->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')->from('stox_yahoo_fundamental_symbol_state as ys')->whereColumn('ys.stock_id', 'portfolio_stocks.id')->where('ys.last_no_data_at', '>', now()->subDays(30));
            });
            // Due-ness is cadence-specific. A quarterly check must never hide a
            // missing annual check (or the reverse), otherwise the tail of the
            // universe permanently disappears from scheduled work.
            $query->where(function ($due): void {
                foreach ([FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL] as $cadence) {
                    $cutoff = now()->subHours($this->checkIntervalHours($cadence));
                    $due->orWhere(function ($cadenceDue) use ($cadence, $cutoff): void {
                        $cadenceDue->whereNotExists(function ($sub) use ($cadence): void {
                            $sub->selectRaw('1')->from('stox_fundamental_provider_checks as pc')
                                ->whereColumn('pc.stock_id', 'portfolio_stocks.id')
                                ->where('pc.cadence', $cadence);
                        })->orWhereExists(function ($sub) use ($cadence, $cutoff): void {
                            $sub->selectRaw('1')->from('stox_fundamental_provider_checks as pc')
                                ->whereColumn('pc.stock_id', 'portfolio_stocks.id')
                                ->where('pc.cadence', $cadence)
                                ->where('pc.last_successful_check_at', '<=', $cutoff);
                        })->orWhereExists(function ($sub) use ($cadence): void {
                            $periodCutoff = now()->subDays($cadence === FundamentalDataService::CADENCE_ANNUAL ? 210 : 120)->toDateString();
                            $sub->selectRaw('1')->from('stox_fundamental_facts as ff')
                                ->whereColumn('ff.stock_id', 'portfolio_stocks.id')
                                ->where('ff.cadence', $cadence)
                                ->where('ff.is_current', true)
                                ->where('ff.period_end', '<=', $periodCutoff)
                                ->whereRaw('ff.period_end = (SELECT MAX(ff2.period_end) FROM stox_fundamental_facts ff2 WHERE ff2.stock_id = ff.stock_id AND ff2.cadence = ff.cadence AND ff2.is_current = 1)')
                                // Once the expected next report is overdue, a successful unchanged
                                // provider check still earns a seven-day polling cooldown.
                                ->whereNotExists(function ($recentCheck) use ($cadence): void {
                                    $recentCheck->selectRaw('1')->from('stox_fundamental_provider_checks as recent_pc')
                                        ->whereColumn('recent_pc.stock_id', 'portfolio_stocks.id')
                                        ->where('recent_pc.cadence', $cadence)
                                        ->where('recent_pc.last_successful_check_at', '>', now()->subDays(7));
                                });
                        });
                    });
                }
            });
            $symbols = ($this->nifty500 ?? app(Nifty500ConstituentService::class))->cachedSymbols();
            if ($symbols !== []) {
                $placeholders = implode(',', array_fill(0, count($symbols), '?'));
                $query->orderByRaw(
                    "CASE WHEN portfolio_stocks.exchange IN (?, ?) AND portfolio_stocks.symbol IN ($placeholders) THEN 0 ELSE 1 END ASC",
                    array_merge(['NSE', 'NSE+'], $symbols),
                );
            }
            $query->orderByRaw("COALESCE((SELECT MIN(pc.last_successful_check_at) FROM stox_fundamental_provider_checks pc WHERE pc.stock_id = portfolio_stocks.id), '1970-01-01') ASC");
        }
        $query->orderBy('id');
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
                $this->reconcileBacklogLocked(min(3, (int) $settings->max_attempts));

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
        $maxAttempts = min(3, max(1, $maxAttempts));
        $exhausted = FundamentalUpdateJob::query()
            ->where('status', 'retry')
            ->where('attempts', '>=', $maxAttempts)
            ->update([
                'status' => 'failed',
                'next_attempt_at' => null,
                'last_error' => DB::raw("CONCAT(COALESCE(last_error, ''), ' [retry budget exhausted; finalized by backlog reconciliation]')"),
            ]);

        $staleJobs = FundamentalUpdateJob::query()
            ->where('status', 'running')
            ->whereNotNull('last_attempted_at')
            ->where('last_attempted_at', '<', now()->subMinutes(30))
            ->get();
        foreach ($staleJobs as $staleJob) {
            $staleJob->forceFill([
                'status' => 'retry',
                'next_attempt_at' => \Carbon\Carbon::parse($staleJob->last_attempted_at)->addDay(),
                'last_error' => trim(($staleJob->last_error ?? '').' [stale running job recovered]'),
            ])->save();
        }
        $recovered = $staleJobs->count();

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
        $noDataByStock = [];
        $validDataByStock = [];
        $failedByStock = [];
        $failedDetailsByStock = [];
        $failedCadencesByStock = [];
        $dataCadencesByStock = [];
        $newlySkipped = [];
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
                $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
                if ($run->scope !== 'manual_stock' && $state?->skipped_at !== null) {
                    $job->forceFill(['status' => 'skipped', 'next_attempt_at' => null, 'last_error' => 'Yahoo no-data skip list is active'])->save();
                    continue;
                }
                if ($stock->is_benchmark) {
                    $job->forceFill([
                        'status' => 'skipped',
                        'next_attempt_at' => null,
                        'last_error' => 'benchmark instruments are not eligible for issuer fundamentals',
                    ])->save();
                    continue;
                }
                $sameRunObservedNoData = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stock->id)->where('run_id', $run->id)->where('outcome', 'no_data')->exists();
                if ($run->scope !== 'manual_stock' && ! $sameRunObservedNoData && ! $this->needsFetch($stock, $job->cadence)) {
                    $job->forceFill(['status' => 'skipped'])->save();
                    continue;
                }

                $checkedAt = now();
                $rows = $this->historicalIngest->fetch($stock, $job->cadence, $run->scope === 'manual_stock');
                $validDataByStock[$stock->id] = ($validDataByStock[$stock->id] ?? false) || $rows !== [];
                if ($rows !== []) $dataCadencesByStock[$stock->id][] = $job->cadence;
                $stats = $this->fundamentals->storeFacts($stock, $rows, $checkedAt);
                // Only confirmed statement rows advance the successful-check
                // ledger. Yahoo no-data is recorded independently below.
                $responseHash = null;
                $checkedProvider = $this->historicalIngest->lastProviderChecked() ?? (string) $settings->provider;
                $responseMetadata = ['source' => $checkedProvider];
                if ($rows !== []) {
                    $this->fundamentals->recordSuccessfulProviderCheck(
                        $stock,
                        $job->cadence,
                        $checkedAt,
                        $checkedProvider,
                        $responseHash,
                        isset($responseMetadata['provider_symbol']) ? (string) $responseMetadata['provider_symbol'] : null,
                        $responseMetadata,
                    );
                }
                $job->forceFill(['status' => 'completed', 'last_error' => null])->save();
                $run->forceFill(['stats_json' => $this->mergeStats($run->stats_json ?? [], $stats)])->save();
            } catch (YahooNoDataOutcome $noData) {
                $stock = $job->stock()->first();
                if ($stock) $noDataByStock[$stock->id][] = $job->cadence;
                $job->forceFill(['status' => 'no_data', 'last_error' => null, 'next_attempt_at' => null])->save();
            } catch (Throwable $error) {
                $sliceError = $error->getMessage();
                $failedByStock[$job->stock_id] = true;
                $deferred = $error instanceof ExchangeRequestDeferred;
                $willRetry = $run->scope !== 'manual_stock' && $job->attempts < min(3, (int) $settings->max_attempts);
                $category = $this->classifyProviderError($error);
                $failedCadencesByStock[$job->stock_id][] = $job->cadence;
                $priorFailureDetails = $failedDetailsByStock[$job->stock_id] ?? [];
                $failedDetailsByStock[$job->stock_id] = [
                    'category' => $category,
                    'categories' => array_values(array_unique(array_merge($priorFailureDetails['categories'] ?? [], [$category]))),
                    'error_cadences' => array_values(array_unique(array_merge($priorFailureDetails['error_cadences'] ?? [], [$job->cadence]))),
                    'retry_pending' => ! empty($priorFailureDetails) ? (! empty($priorFailureDetails['retry_pending']) && $willRetry) : $willRetry,
                    'attempts' => (int) $job->attempts,
                    'max_attempts' => min(3, (int) $settings->max_attempts),
                ];
                $job->forceFill([
                    'status' => $willRetry ? 'retry' : 'failed',
                    'next_attempt_at' => $willRetry
                        ? now()->addSeconds($deferred ? max(86400, $error->retryAfterSeconds) : 86400)
                        : null,
                    'last_error' => $error->getMessage(),
                ])->save();
                $run->forceFill(['last_error' => $error->getMessage()])->save();
            }

            if ($settings->request_delay_ms > 0) {
                usleep($settings->request_delay_ms * 1000);
            }
        }

        foreach ($noDataByStock as $stockId => $cadences) {
            if (! empty($failedByStock[$stockId])) {
                DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stockId)->update(['consecutive_no_data' => 0, 'last_no_data_at' => null, 'updated_at' => now()]);
                $observedCadences = array_values(array_unique(array_merge($cadences, $failedCadencesByStock[$stockId] ?? [], $dataCadencesByStock[$stockId] ?? [])));
                $existing = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stockId)->where('run_id', $run->id)->first();
                $details = $this->mergeObservationDetails($existing?->details, array_merge([
                    'consecutive_no_data_reset' => true,
                    'data_success' => ! empty($validDataByStock[$stockId]),
                    'saw_no_data' => $existing?->outcome === 'no_data' || ! empty($cadences),
                    'no_data_cadences' => $cadences,
                    'observed_cadences' => $observedCadences,
                ], $failedDetailsByStock[$stockId] ?? []));
                if ($existing) {
                    DB::table('stox_yahoo_fundamental_observations')->where('id', $existing->id)->update(['outcome' => 'provider_error', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'details' => json_encode($details), 'updated_at' => now()]);
                } else {
                    DB::table('stox_yahoo_fundamental_observations')->insert(['stock_id' => $stockId, 'run_id' => $run->id, 'outcome' => 'provider_error', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'observed_at' => now(), 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
                }
                continue;
            }
            if (! empty($validDataByStock[$stockId])) {
                DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stockId)->update(['consecutive_no_data' => 0, 'last_no_data_at' => null, 'updated_at' => now()]);
                $observedCadences = array_values(array_unique(array_merge($cadences, $dataCadencesByStock[$stockId] ?? [])));
                $existing = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stockId)->where('run_id', $run->id)->first();
                $details = $this->mergeObservationDetails($existing?->details, ['reset' => true, 'data_success' => true, 'saw_no_data' => $existing?->outcome === 'no_data', 'no_data_cadences' => $existing?->outcome === 'no_data' ? array_filter(explode(',', (string) $existing->cadences)) : [], 'observed_cadences' => $observedCadences]);
                if ($existing) {
                    DB::table('stox_yahoo_fundamental_observations')->where('id', $existing->id)->update(['outcome' => $existing->outcome === 'provider_error' ? 'provider_error' : 'data', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'details' => json_encode($details), 'updated_at' => now()]);
                } else {
                    DB::table('stox_yahoo_fundamental_observations')->insert(['stock_id' => $stockId, 'run_id' => $run->id, 'outcome' => 'data', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'observed_at' => now(), 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
                }
                continue;
            }
            DB::transaction(function () use ($stockId, $run, $cadences, &$newlySkipped): void {
                $existing = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stockId)->where('run_id', $run->id)->first();
                if ($existing) {
                    $observedCadences = array_filter(array_merge(explode(',', (string) $existing->cadences), $cadences));
                    $details = $this->mergeObservationDetails($existing->details, [
                        'saw_no_data' => true,
                        'no_data_cadences' => $cadences,
                        'observed_cadences' => $observedCadences,
                    ]);
                    DB::table('stox_yahoo_fundamental_observations')->where('id', $existing->id)->update(['cadences' => implode(',', array_unique($observedCadences)), 'details' => json_encode($details), 'updated_at' => now()]);

                    return;
                }
                $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stockId)->lockForUpdate()->first();
                $count = (int) ($state->consecutive_no_data ?? 0) + 1;
                $skippedAt = $count >= 20 ? ($state->skipped_at ?? now()) : ($state->skipped_at ?? null);
                DB::table('stox_yahoo_fundamental_symbol_state')->updateOrInsert(['stock_id' => $stockId], ['consecutive_no_data' => $count, 'last_no_data_at' => now(), 'skipped_at' => $skippedAt, 'skip_reason' => $count >= 20 ? '20 consecutive valid Yahoo no-data outcomes' : null, 'created_at' => $state->created_at ?? now(), 'updated_at' => now()]);
                DB::table('stox_yahoo_fundamental_observations')->insert(['stock_id' => $stockId, 'run_id' => $run->id, 'outcome' => 'no_data', 'cadences' => implode(',', array_unique($cadences)), 'consecutive_count' => $count, 'observed_at' => now(), 'details' => json_encode(['threshold' => 20, 'saw_no_data' => true, 'no_data_cadences' => $cadences]), 'created_at' => now(), 'updated_at' => now()]);
                if ($count === 20 && $state->skipped_at === null) $newlySkipped[] = $stockId;
            });
        }
        foreach (array_keys($failedByStock) as $stockId) {
            if (array_key_exists($stockId, $noDataByStock)) continue;
            DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stockId)->update(['consecutive_no_data' => 0, 'last_no_data_at' => null, 'updated_at' => now()]);
            $existing = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stockId)->where('run_id', $run->id)->first();
            $observedCadences = array_values(array_unique(array_merge(
                array_filter(explode(',', (string) ($existing->cadences ?? ''))),
                $failedCadencesByStock[$stockId] ?? [],
                $dataCadencesByStock[$stockId] ?? [],
            )));
            $details = $this->mergeObservationDetails($existing?->details, array_merge([
                'consecutive_no_data_reset' => true,
                'data_success' => ! empty($validDataByStock[$stockId]),
                'saw_no_data' => $existing?->outcome === 'no_data',
                'no_data_cadences' => $existing?->outcome === 'no_data' ? array_filter(explode(',', (string) $existing->cadences)) : [],
                'observed_cadences' => $observedCadences,
            ], $failedDetailsByStock[$stockId] ?? []));
            if ($existing) {
                DB::table('stox_yahoo_fundamental_observations')->where('id', $existing->id)->update(['outcome' => 'provider_error', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'details' => json_encode($details), 'updated_at' => now()]);
            } else {
                DB::table('stox_yahoo_fundamental_observations')->insert(['stock_id' => $stockId, 'run_id' => $run->id, 'outcome' => 'provider_error', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'observed_at' => now(), 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (array_keys($validDataByStock) as $stockId) {
            if (array_key_exists($stockId, $noDataByStock) || array_key_exists($stockId, $failedByStock)) continue;
            DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stockId)->update(['consecutive_no_data' => 0, 'last_no_data_at' => null, 'updated_at' => now()]);
            $existing = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stockId)->where('run_id', $run->id)->first();
            $observedCadences = array_values(array_unique(array_merge(array_filter(explode(',', (string) ($existing->cadences ?? ''))), $dataCadencesByStock[$stockId] ?? [])));
            $details = $this->mergeObservationDetails($existing?->details, ['reset' => true, 'data_success' => true, 'saw_no_data' => $existing?->outcome === 'no_data', 'no_data_cadences' => $existing?->outcome === 'no_data' ? array_filter(explode(',', (string) $existing->cadences)) : [], 'observed_cadences' => $observedCadences]);
            if ($existing) {
                DB::table('stox_yahoo_fundamental_observations')->where('id', $existing->id)->update(['outcome' => $existing->outcome === 'provider_error' ? 'provider_error' : 'data', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'details' => json_encode($details), 'updated_at' => now()]);
            } else {
                DB::table('stox_yahoo_fundamental_observations')->insert(['stock_id' => $stockId, 'run_id' => $run->id, 'outcome' => 'data', 'cadences' => implode(',', $observedCadences), 'consecutive_count' => 0, 'observed_at' => now(), 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
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

        foreach ($newlySkipped as $stockId) {
            $stock = Stock::query()->find($stockId);
            $this->opsAlerts->recordUnattendedFailure(
                AdminOperationalAlertService::KEY_FUNDAMENTALS_NO_DATA_SKIP,
                'Yahoo fundamentals symbol needs review',
                sprintf('%s (%s) reached 20 consecutive valid Yahoo no-data observations and was added to the automatic skip list.', $stock?->symbol ?? 'Unknown symbol', $stock?->exchange ?? 'unknown exchange'),
                ['stock_id' => $stockId, 'run_id' => $run->id],
                'info',
            );
        }
        if ($newlySkipped !== []) $this->opsAlerts->syncAndNotify();

        $health = app(FundamentalPipelineHealth::class)->recent();
        if (! empty($health['action_required'])) {
            $this->opsAlerts->recordUnattendedFailure(
                AdminOperationalAlertService::KEY_FUNDAMENTALS_UPDATE_FAILED,
                'Fundamentals provider requires review',
                sprintf('V7 fundamentals run #%d reached an actionable provider health condition: %s.', $run->id, $health['status']),
                ['run_id' => $run->id, 'status' => $run->status, 'pipeline_health' => $health],
            );
            $this->opsAlerts->syncAndNotify();
        } elseif ($run->fresh()->status === 'completed'
            && DB::table('stox_yahoo_fundamental_observations')->where('run_id', $run->id)->where('outcome', 'data')->exists()
            && (int) ($health['consecutive_recovery_sessions'] ?? 0) >= 2) {
            if ($this->opsAlerts->clearUnattendedFailure(AdminOperationalAlertService::KEY_FUNDAMENTALS_UPDATE_FAILED)) {
                $this->opsAlerts->syncAndNotify();
            }
        }

        return $run->fresh()->toArray();
    }

    private function classifyProviderError(Throwable $error): string
    {
        if ($error instanceof \Error || $error instanceof \LogicException) return 'pipeline_crash';
        if ($error instanceof ExchangeRequestDeferred || preg_match('/rate.?limit|too many requests|quota|\b429\b/i', $error->getMessage())) return 'rate_limit';
        if (preg_match('/unauthori[sz]ed|forbidden|\b401\b|\b403\b|authentication|credential/i', $error->getMessage())) return 'auth';
        if (preg_match('/timeout|timed out|connection|dns|network|socket|curl|transport/i', $error->getMessage())) return 'connectivity';
        if (preg_match('/parse|malformed|invalid json|schema|normaliz/i', $error->getMessage())) return 'parsing';
        return 'upstream';
    }

    /** @return array<string,mixed> */
    private function mergeObservationDetails(?string $existingJson, array $updates): array
    {
        $details = is_string($existingJson) ? (json_decode($existingJson, true) ?: []) : [];
        foreach ($updates as $key => $value) {
            if (in_array($key, ['observed_cadences', 'no_data_cadences', 'categories', 'error_cadences'], true)) {
                $prior = is_array($details[$key] ?? null) ? $details[$key] : [];
                $next = is_array($value) ? $value : [$value];
                $details[$key] = array_values(array_unique(array_merge($prior, $next)));
            } elseif (in_array($key, ['data_success', 'saw_no_data', 'consecutive_no_data_reset', 'reset'], true)) {
                $details[$key] = ! empty($details[$key]) || (bool) $value;
            } elseif ($key === 'retry_pending' && array_key_exists($key, $details)) {
                $details[$key] = ! empty($details[$key]) && (bool) $value;
            } else {
                $details[$key] = $value;
            }
        }
        return $details;
    }

    private function refreshRunCounters(FundamentalUpdateRun $run): void
    {
        $counts = $run->jobs()
            ->selectRaw("COUNT(*) as requested, SUM(status IN ('completed', 'no_data', 'failed', 'skipped', 'superseded')) as processed, SUM(status = 'completed') as succeeded, SUM(status = 'failed') as failed, SUM(status IN ('skipped', 'superseded', 'no_data')) as skipped")
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
        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        if ($state?->skipped_at !== null || ($state?->last_no_data_at !== null && \Carbon\Carbon::parse($state->last_no_data_at)->addDays(30)->gt(now()))) return false;
        $latestPeriod = DB::table('stox_fundamental_facts')->where('stock_id', $stock->id)->where('cadence', $cadence)->where('is_current', true)->max('period_end');
        $latest = DB::table('stox_fundamental_provider_checks')
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->max('last_successful_check_at');
        if ($latestPeriod !== null) {
            $days = $cadence === FundamentalDataService::CADENCE_ANNUAL ? 210 : 120;
            if (\Carbon\Carbon::parse($latestPeriod)->addDays($days)->gt(now())) return false;
            // The expected reporting period is overdue, but an unchanged
            // successful response should not cause hourly refetches.
            return $latest === null || \Carbon\Carbon::parse($latest)->addDays(7)->lte(now());
        }

        // Facts alone do not prove that this cadence has received a successful
        // provider check. Preserve the durable ledger as the source of truth so
        // migrated/historical facts cannot starve a cadence forever.
        if ($latest === null) {
            return true;
        }

        $hours = $this->checkIntervalHours($cadence);

        return \Carbon\Carbon::parse($latest)->addHours($hours)->lte(now());
    }

    private function checkIntervalHours(string $cadence): int
    {
        return $cadence === FundamentalDataService::CADENCE_ANNUAL ? 24 * 210 : 24 * 120;
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
