<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\ExternalHttp;

class StockMasterSyncService
{
    public function __construct(
        protected ProviderResolverService $resolver,
        protected SyncLogService $syncLog,
        protected PriceFetchService $priceFetch,
        protected BseEquityMasterService $bseMaster,
        protected DualListedNseRepairService $dualListedRepair,
    ) {}


    /**
     * Preview by default. Apply fills only absent ISINs under an exact reviewed
     * plan; it never calls ordinary sync, deactivation, backfill or dual repair.
     */
    public function reconcileMissingBseIsins(bool $apply = false, ?string $expectedPlan = null): array
    {
        if ($apply && preg_match('/^[a-f0-9]{64}$/', (string) $expectedPlan) !== 1) {
            throw new \InvalidArgumentException('Apply requires the SHA-256 from a fresh BSE identity preview.');
        }
        $parts = parse_url($this->bseMaster->listApiUrl());
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.bseindia.com'
            || rtrim((string) ($parts['path'] ?? ''), '/') !== '/BseIndiaAPI/api/ListofScripData/w'
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
            || strtoupper((string) ($query['status'] ?? '')) !== 'ACTIVE'
            || strtoupper((string) ($query['segment'] ?? '')) !== 'EQUITY') {
            throw new \RuntimeException('BSE identity reconciliation requires the official active-equity API.');
        }
        if ($apply && ! $this->syncLog->isEnabled()) {
            throw new \RuntimeException('Enable sync-log retention before applying an audited BSE identity plan.');
        }
        $lock = null;
        $leaseAcquired = false;
        $failure = null;
        $runId = null;
        $jobName = 'bse-identity-reconciliation';
        try {
            // Network acquisition precedes the short database transaction.
            $source = $this->bseMaster->fetchFromApi();
            if ($source === [] || count($source) > 20000) {
                throw new \RuntimeException('BSE active-equity source is empty or exceeds the bounded reconciliation size.');
            }
            $sourceFetchedAt = now('UTC');
            $readStocks = fn (bool $lockRows) => Stock::query()->orderBy('id')
                ->when($lockRows, fn ($query) => $query->lockForUpdate())
                ->limit(20001)
                ->get(['id', 'symbol', 'exchange', 'isin', 'bse_scrip_code', 'is_active', 'is_benchmark', 'admin_deactivated'])
                ->map(fn (Stock $stock) => $stock->getAttributes())->all();
            $planner = new BseIdentityReconciliationPlan;
            if (! $apply) {
                $stocks = $readStocks(false);
                if (count($stocks) > 20000) {
                    throw new \RuntimeException('Master exceeds the bounded reconciliation size; no changes applied.');
                }

                return $planner->build($stocks, $source) + ['applied_count' => 0, 'dry_run' => true];
            }
            // Network work does not consume the universe writer lease.
            $cache = \Illuminate\Support\Facades\Cache::store('database');
            $store = $cache->getStore();
            if (! $store instanceof \Illuminate\Cache\DatabaseStore) {
                throw new \RuntimeException('BSE identity apply requires the existing database universe lease.');
            }
            $lock = $cache->lock('portfolio-universe-price-sync', 300);
            $db = (new Stock)->getConnection();
            if (! $lock instanceof \Illuminate\Cache\DatabaseLock || $lock->getConnectionName() !== $db->getName()) {
                throw new \RuntimeException('Universe lease and identity writes must share one database connection.');
            }
            if (! $lock->get()) {
                throw new \RuntimeException('Universe metadata/price owner is busy; no identity changes applied.');
            }
            $leaseAcquired = true;
            $startedAt = now()->getTimestamp();
            $startedMonotonic = hrtime(true);
            $lockTable = config('cache.stores.database.lock_table') ?: 'cache_locks';
            $lockKey = $store->getPrefix().'portfolio-universe-price-sync';
            $runId = $this->syncLog->beginRun($jobName);
            if ($runId === null) {
                throw new \RuntimeException('Could not create an audited BSE identity run; no identities changed.');
            }
            $result = $db->transaction(function () use ($db, $lock, $lockTable, $lockKey, $startedAt, $startedMonotonic, $readStocks, $planner, $source, $sourceFetchedAt, $expectedPlan, $runId, $jobName): array {
                // This row fence stays held through commit/rollback. Even an
                // expired cache lease cannot be replaced by a new price owner
                // while this metadata transaction is still active.
                $db->table($lockTable)->where('key', $lockKey)->lockForUpdate()->first();
                $assertLease = function () use ($db, $lock, $lockTable, $lockKey, $startedAt, $startedMonotonic): void {
                    $lease = $db->table($lockTable)->where('key', $lockKey)->first();
                    $now = now()->getTimestamp();
                    if ($lease === null || ! hash_equals($lock->owner(), (string) $lease->owner)
                        || (int) $lease->expiration <= $now) {
                        throw new \RuntimeException('BSE identity lease was lost or expired; all changes rolled back.');
                    }
                    if (max((hrtime(true) - $startedMonotonic) / 1_000_000_000, abs($now - $startedAt)) >= 120) {
                        throw new \RuntimeException('BSE identity apply budget exhausted; all changes rolled back.');
                    }
                };
                $assertLease();
                $stocks = $readStocks(true);
                if (count($stocks) > 20000) {
                    throw new \RuntimeException('Master exceeds the bounded reconciliation size; no changes applied.');
                }
                $plan = $planner->build($stocks, $source);
                if ($plan['candidate_count'] > 5000) {
                    throw new \RuntimeException('Identity plan exceeds the bounded apply size; no changes applied.');
                }
                if (! hash_equals($plan['plan_sha256'], (string) $expectedPlan)) {
                    throw new \RuntimeException('BSE source or stock identities changed since preview; generate and review a new plan.');
                }
                $this->syncLog->log($runId, $jobName, 'info', 'Applying reviewed BSE identity-only plan', [
                    'plan_sha256' => $plan['plan_sha256'], 'source_rows_sha256' => $plan['source_rows_sha256'],
                    'master_rows_sha256' => $plan['master_rows_sha256'], 'candidate_count' => $plan['candidate_count'],
                ]);
                $header = \App\Models\SyncLog::query()->where('run_id', $runId)
                    ->where('message', 'Applying reviewed BSE identity-only plan')->get();
                if ($header->count() !== 1 || ($header->first()->context['plan_sha256'] ?? '') !== $plan['plan_sha256']
                    || ($header->first()->context['source_rows_sha256'] ?? '') !== $plan['source_rows_sha256']
                    || ($header->first()->context['master_rows_sha256'] ?? '') !== $plan['master_rows_sha256']
                    || ($header->first()->context['candidate_count'] ?? null) !== $plan['candidate_count']) {
                    throw new \RuntimeException('Required BSE identity plan audit was not persisted; no identities changed.');
                }
                foreach ($plan['targets'] as $target) {
                    $assertLease();
                    $changed = Stock::query()->whereKey($target['stock_id'])
                        ->where('exchange', 'BSE')->where('is_active', true)->where('admin_deactivated', false)
                        ->where('is_benchmark', false)->where('symbol', $target['symbol'])
                        ->where('bse_scrip_code', $target['scrip_code'])
                        ->where(function ($query) { $query->whereNull('isin')->orWhere('isin', ''); })
                        ->update(['isin' => $target['new_isin']]);
                    if ($changed !== 1) {
                        throw new \RuntimeException('A BSE identity target changed during apply; all changes rolled back.');
                    }
                }
                foreach (array_chunk($plan['targets'], 100) as $targets) {
                    $this->syncLog->log($runId, $jobName, 'info', 'BSE identity-only changes', [
                        'plan_sha256' => $plan['plan_sha256'], 'changes' => $targets,
                    ]);
                }

                $recorded = \App\Models\SyncLog::query()->where('run_id', $runId)
                    ->where('message', 'BSE identity-only changes')->orderBy('id')->get();
                $changes = [];
                foreach ($recorded as $log) {
                    if (($log->context['plan_sha256'] ?? '') !== $plan['plan_sha256']) {
                        throw new \RuntimeException('BSE identity change audit does not match the reviewed plan.');
                    }
                    array_push($changes, ...($log->context['changes'] ?? []));
                }
                // MySQL JSON normalizes object key order. Preserve the ordered
                // list and exact keys/scalar types/values, canonicalizing only
                // each flat change object's associative key order.
                $canonicalChanges = static fn (array $rows): array => array_map(static function (array $row): array {
                    ksort($row, SORT_STRING);

                    return $row;
                }, $rows);
                if ($canonicalChanges($changes) !== $canonicalChanges($plan['targets'])) {
                    throw new \RuntimeException('Required BSE identity change audit was not persisted; all changes rolled back.');
                }
                $assertLease();
                $this->syncLog->completeRun($runId, 'success', [
                    'processed' => $plan['candidate_count'],
                    'skipped' => array_sum($plan['reason_counts']) - $plan['candidate_count'],
                ], 'BSE ISIN-only reconciliation completed; prices, holdings and lifecycle were not changed.');
                $completed = \App\Models\SyncRun::query()->find($runId);
                if ($completed === null || $completed->status !== 'success'
                    || (int) $completed->stocks_processed !== $plan['candidate_count']) {
                    throw new \RuntimeException('Required BSE identity success audit was not persisted; all changes rolled back.');
                }

                $assertLease();
                $result = ['applied_count' => $plan['candidate_count'], 'dry_run' => false];
                $audit = \App\Models\BseIdentityReconciliationAudit::query()->create([
                    'id' => $runId, 'plan_sha256' => $plan['plan_sha256'],
                    'plan_json' => $plan, 'result_json' => $result,
                    'source_fetched_at' => $sourceFetchedAt, 'applied_at' => now('UTC'),
                ])->fresh();
                if ($audit === null || $audit->plan_sha256 !== $plan['plan_sha256']
                    || $planner->hashPlan($audit->plan_json) !== $plan['plan_sha256']
                    || ($audit->result_json['applied_count'] ?? null) !== $plan['candidate_count']
                    || ($audit->result_json['dry_run'] ?? null) !== false) {
                    throw new \RuntimeException('Durable BSE identity audit was not persisted; all changes rolled back.');
                }
                $assertLease();

                return $plan + $result + ['audit_id' => $audit->id];
            });
            return $result;
        } catch (\Throwable $error) {
            $failure = $error;
            if ($runId !== null) {
                try {
                    $this->syncLog->completeRun($runId, 'failed', [], 'BSE ISIN-only reconciliation refused or rolled back.');
                } catch (\Throwable) {
                    // Preserve the original refusal/persistence failure.
                }
            }
            throw $error;
        } finally {
            try {
                if ($leaseAcquired) {
                    $lock?->release();
                }
            } catch (\Throwable $releaseError) {
                if ($failure === null) {
                    throw $releaseError;
                }
            }
        }
    }

    /**
     * @return array{
     *   added: int,
     *   updated: int,
     *   deactivated: int,
     *   skipped: int,
     *   source: string,
     *   backfill_new_stocks: int,
     *   backfill_new_rows: int,
     *   backfill_new_failures: int,
     *   bse_skipped_isin_dup: int,
     *   backfill_skipped: bool,
     *   dual_listed_pairs_repaired: int,
     *   dual_listed_bse_prices_deleted: int,
     *   dual_listed_nse_backfill_rows: int
     * }
     */
    public function syncStockMaster(bool $backfillNewSymbols = true): array
    {
        $jobName = SyncLogService::JOB_STOCK_MASTER;
        $runId = $this->syncLog->beginRun($jobName);

        $stats = [
            'added' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'source' => 'nse',
            'backfill_new_stocks' => 0,
            'backfill_new_rows' => 0,
            'backfill_new_failures' => 0,
            'bse_skipped_isin_dup' => 0,
            'backfill_skipped' => false,
            'dual_listed_pairs_repaired' => 0,
            'dual_listed_bse_prices_deleted' => 0,
            'dual_listed_nse_backfill_rows' => 0,
        ];

        $this->syncLog->log($runId, $jobName, 'info', 'Stock master sync started', [
            'start_time' => now()->toIso8601String(),
            'backfill_new_symbols' => $backfillNewSymbols,
        ]);

        try {
            $stats = $this->runStockMasterSync($runId, $jobName, $stats, $backfillNewSymbols);

            $summary = sprintf(
                'Stock master sync complete (%s): added=%d updated=%d deactivated=%d skipped=%d new_backfill_stocks=%d new_backfill_rows=%d new_backfill_failures=%d',
                $stats['source'],
                $stats['added'],
                $stats['updated'],
                $stats['deactivated'],
                $stats['skipped'],
                $stats['backfill_new_stocks'],
                $stats['backfill_new_rows'],
                $stats['backfill_new_failures'],
            );

            $this->syncLog->log($runId, $jobName, 'info', 'Stock master sync completed', array_merge($stats, [
                'end_time' => now()->toIso8601String(),
            ]));
            $this->syncLog->completeRun($runId, 'success', [
                'processed' => $stats['added'] + $stats['updated'],
                'skipped' => $stats['skipped'],
            ], $summary);

            return $stats;
        } catch (\Throwable $e) {
            $this->syncLog->log($runId, $jobName, 'error', 'Stock master sync failed', [
                'failure_reason' => $e->getMessage(),
            ]);
            $this->syncLog->completeRun($runId, 'failed', [], $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param  array{
     *   added: int,
     *   updated: int,
     *   deactivated: int,
     *   skipped: int,
     *   source: string,
     *   backfill_new_stocks: int,
     *   backfill_new_rows: int,
     *   backfill_new_failures: int,
     *   bse_skipped_isin_dup: int,
     *   backfill_skipped: bool,
     *   dual_listed_pairs_repaired: int,
     *   dual_listed_bse_prices_deleted: int,
     *   dual_listed_nse_backfill_rows: int
     * }
     */
    protected function runStockMasterSync(
        ?string $runId,
        string $jobName,
        array $stats,
        bool $backfillNewSymbols = true,
    ): array {
        $rows = $this->fetchNseEquityRows();
        $seen = [];
        $newNseStockIds = [];
        $nseIsins = [];

        foreach ($rows as $row) {
            try {
                $normalized = $this->resolver->normalizeSymbol($row['symbol'], 'NSE');
            } catch (\InvalidArgumentException) {
                $stats['skipped']++;
                continue;
            }

            $key = $normalized['symbol'].'|'.$normalized['exchange'];
            if (isset($seen[$key])) {
                $this->syncLog->log($runId, $jobName, 'warning', 'Duplicate symbol conflict during stock master sync', [
                    'symbol' => $normalized['symbol'],
                    'exchange' => $normalized['exchange'],
                ]);
                $stats['skipped']++;

                continue;
            }
            $seen[$key] = true;

            $result = $this->upsertMasterRow(
                $normalized['symbol'],
                $normalized['exchange'],
                $row['name'],
                $row['isin'] ?? null,
                $row['series'] ?? null,
            );

            $stats[$result['action']]++;
            if (! empty($row['isin'])) {
                $nseIsins[strtoupper($row['isin'])] = true;
            }
            if ($result['action'] === 'added' && $normalized['exchange'] === 'NSE') {
                $newNseStockIds[] = $result['stock']->id;
            }
        }

        $stats['deactivated'] += $this->deactivateMissing(array_keys($seen), 'NSE');

        $newBseStockIds = [];
        $dualListedIsins = [];
        if (config('portfolio.stock_master.bse_enabled')) {
            $bseStats = $this->syncBseMaster($runId, $jobName, $nseIsins, $newBseStockIds, $dualListedIsins);
            $stats['added'] += $bseStats['added'];
            $stats['updated'] += $bseStats['updated'];
            $stats['deactivated'] += $bseStats['deactivated'];
            $stats['skipped'] += $bseStats['skipped'];
            $stats['bse_skipped_isin_dup'] = $bseStats['skipped_isin_dup'];
            $stats['source'] = 'nse+bse';
        }

        $this->reconcileDualListedFlags($dualListedIsins);

        $newStockIds = array_values(array_unique(array_merge($newNseStockIds, $newBseStockIds)));
        if ($backfillNewSymbols && $newStockIds !== []) {
            $maxBackfill = (int) config('portfolio.stock_master.max_backfill_per_sync', 25);
            $idsToBackfill = $maxBackfill > 0
                ? array_slice($newStockIds, 0, $maxBackfill)
                : $newStockIds;

            if ($maxBackfill > 0 && count($newStockIds) > $maxBackfill) {
                $stats['backfill_skipped'] = true;
                $this->syncLog->log($runId, $jobName, 'info', 'Stock master backfill capped for this run', [
                    'new_symbols' => count($newStockIds),
                    'backfill_limit' => $maxBackfill,
                ]);
            }

            $backfill = $this->backfillNewlyAddedStocks($idsToBackfill);
            $stats['backfill_new_stocks'] = $backfill['processed'];
            $stats['backfill_new_rows'] = $backfill['stored_rows'];
            $stats['backfill_new_failures'] = $backfill['failed'];
            $this->syncLog->log($runId, $jobName, 'info', 'Backfill for newly added symbols completed', $backfill);
        } elseif (! $backfillNewSymbols && $newStockIds !== []) {
            $stats['backfill_skipped'] = true;
            $this->syncLog->log($runId, $jobName, 'info', 'Skipped price backfill for newly added symbols (UI/short request)', [
                'new_symbols' => count($newStockIds),
            ]);
        }

        $repairBackfill = $backfillNewSymbols && (bool) config('portfolio.stock_master.dual_listed_repair_backfill', true);
        $repairStats = $this->dualListedRepair->repair(
            dryRun: false,
            backfill: $repairBackfill,
            maxBackfill: $repairBackfill
                ? max(0, (int) config('portfolio.stock_master.dual_listed_repair_max_backfill', 50))
                : 0,
        );
        $stats['dual_listed_pairs_repaired'] = $repairStats['pairs_found'];
        $stats['dual_listed_bse_prices_deleted'] = $repairStats['bse_prices_deleted'];
        $stats['dual_listed_nse_backfill_rows'] = $repairStats['nse_backfill_rows'];
        if ($repairStats['errors'] !== []) {
            $this->syncLog->log($runId, $jobName, 'warning', 'Dual-listed NSE repair completed with errors', $repairStats);
        } elseif ($repairStats['pairs_found'] > 0) {
            $this->syncLog->log($runId, $jobName, 'info', 'Dual-listed NSE repair completed', $repairStats);
        }

        return $stats;
    }

    /**
     * @param  array<string, true>  $nseIsins
     * @param  array<int, int>  $newBseStockIds
     * @return array{added: int, updated: int, deactivated: int, skipped: int, skipped_isin_dup: int}
     */
    public function syncBseMaster(
        ?string $runId = null,
        ?string $jobName = null,
        array $nseIsins = [],
        array &$newBseStockIds = [],
        array &$dualListedIsins = [],
    ): array {
        $jobName ??= SyncLogService::JOB_STOCK_MASTER;

        try {
            $rows = $this->bseMaster->fetchEquityRows();
        } catch (\Throwable $e) {
            $this->syncLog->log(
                $runId,
                $jobName,
                'error',
                'BSE stock master fetch failed: '.$e->getMessage(),
            );

            throw $e;
        }

        if ($rows === []) {
            $this->syncLog->log($runId, $jobName, 'warning', 'BSE stock master returned no rows');

            return ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0, 'skipped_isin_dup' => 0];
        }

        $stats = ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0, 'skipped_isin_dup' => 0];
        $seen = [];

        foreach ($rows as $row) {
            $symbol = strtoupper(trim($row['symbol'] ?? ''));
            if ($symbol === '') {
                $stats['skipped']++;
                continue;
            }

            $isin = isset($row['isin']) ? strtoupper(trim((string) $row['isin'])) : '';
            if ($isin !== '' && isset($nseIsins[$isin])) {
                $stats['skipped_isin_dup']++;
                $dualListedIsins[$isin] = true;
                $this->deactivateBseDuplicateForSymbol($symbol, $isin);

                continue;
            }

            try {
                $normalized = $this->resolver->normalizeSymbol($symbol, 'BSE');
            } catch (\InvalidArgumentException) {
                $stats['skipped']++;
                continue;
            }

            $key = $normalized['symbol'].'|BSE';
            if (isset($seen[$key])) {
                $stats['skipped']++;
                continue;
            }
            $seen[$key] = true;

            $result = $this->upsertMasterRow(
                $normalized['symbol'],
                $normalized['exchange'],
                $row['name'] ?? $symbol,
                $isin !== '' ? $isin : null,
                null,
                isset($row['scrip_code']) ? trim((string) $row['scrip_code']) ?: null : null,
            );
            $stats[$result['action']]++;
            if ($result['action'] === 'added') {
                $newBseStockIds[] = $result['stock']->id;
            }
        }

        $stats['deactivated'] = $this->deactivateMissing(array_keys($seen), 'BSE');
        $stats['deactivated'] += $this->deactivateBseDuplicatesOfNse(array_keys($nseIsins));

        return $stats;
    }

    /**
     * @param  array<string, true>  $dualListedIsins
     */
    protected function reconcileDualListedFlags(array $dualListedIsins): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'is_dual_listed')) {
            return;
        }

        Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->update(['is_dual_listed' => false]);

        $dualListedSymbols = Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->distinct()
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper((string) $symbol))
            ->filter()
            ->all();

        $nseQuery = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false);

        $nseQuery->where(function ($query) use ($dualListedIsins, $dualListedSymbols): void {
            if ($dualListedIsins !== []) {
                $query->whereIn('isin', array_keys($dualListedIsins));
            }
            if ($dualListedSymbols !== []) {
                $method = $dualListedIsins === [] ? 'whereIn' : 'orWhereIn';
                $query->{$method}('symbol', $dualListedSymbols);
            }
        });

        if ($dualListedIsins === [] && $dualListedSymbols === []) {
            return;
        }

        $nseQuery->update(['is_dual_listed' => true]);
    }

    /**
     * @param  array<int, string>  $nseIsins
     */
    protected function deactivateBseDuplicatesOfNse(array $nseIsins): int
    {
        if ($nseIsins === []) {
            return 0;
        }

        $nseSymbols = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper((string) $symbol))
            ->all();

        $deactivated = Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereIn('isin', $nseIsins)
            ->update(['is_active' => false]);

        if ($nseSymbols !== []) {
            $deactivated += Stock::query()
                ->where('exchange', 'BSE')
                ->where('is_benchmark', false)
                ->where('is_active', true)
                ->whereIn('symbol', $nseSymbols)
                ->update(['is_active' => false]);
        }

        return $deactivated;
    }

    protected function deactivateBseDuplicateForSymbol(string $symbol, string $isin): void
    {
        Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->where('symbol', strtoupper(trim($symbol)))
            ->update([
                'isin' => strtoupper(trim($isin)),
                'is_active' => false,
            ]);
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string, series: ?string}>
     */
    public function fetchNseEquityRows(): array
    {
        $url = config('portfolio.stock_master.nse_equity_csv_url');
        $content = $this->downloadCsv($url);

        return $this->parseNseEquityCsv($content);
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string, series: ?string}>
     */
    public function parseNseEquityCsv(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $headerMap = [];
        foreach ($header as $index => $column) {
            $headerMap[strtoupper(trim($column))] = $index;
        }

        $symbolIdx = $headerMap['SYMBOL'] ?? 0;
        $nameIdx = $headerMap['NAME OF COMPANY'] ?? 1;
        $seriesIdx = $headerMap['SERIES'] ?? 2;
        $isinIdx = $headerMap['ISIN NUMBER'] ?? $headerMap['ISIN'] ?? null;

        $allowedSeries = ['EQ', 'BE', 'BZ'];
        $seriesPriority = ['EQ' => 1, 'BE' => 2, 'BZ' => 3];
        /** @var array<string, array{symbol: string, name: string, isin: ?string, series: string, priority: int}> $candidates */
        $candidates = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line, ',', '"', '\\');
            $series = strtoupper(trim($cols[$seriesIdx] ?? 'EQ'));
            if (! in_array($series, $allowedSeries, true)) {
                continue;
            }

            $symbol = strtoupper(trim($cols[$symbolIdx] ?? ''));
            if ($symbol === '' || $this->resolver->isMalformed($symbol)) {
                continue;
            }

            $isinRaw = $isinIdx !== null ? strtoupper(trim($cols[$isinIdx] ?? '')) : '';
            $isin = $isinRaw !== '' ? $isinRaw : null;
            $key = $isin ?? ('SYM:'.$symbol);
            $priority = $seriesPriority[$series] ?? 99;
            $name = trim($cols[$nameIdx] ?? $symbol);

            $existing = $candidates[$key] ?? null;
            if ($existing !== null && $existing['priority'] <= $priority) {
                continue;
            }

            $candidates[$key] = [
                'symbol' => $symbol,
                'name' => $name,
                'isin' => $isin,
                'series' => $series,
                'priority' => $priority,
            ];
        }

        return array_values(array_map(
            static fn (array $row) => [
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'isin' => $row['isin'],
                'series' => $row['series'],
            ],
            $candidates,
        ));
    }

    /**
     * @return array{action: 'added'|'updated', stock: Stock}
     */
    protected function upsertMasterRow(
        string $symbol,
        string $exchange,
        string $name,
        ?string $isin,
        ?string $series,
        ?string $bseScripCode = null,
    ): array {
        $stock = Stock::query()->firstOrNew([
            'symbol' => $symbol,
            'exchange' => $exchange,
            'is_benchmark' => false,
        ]);

        $isNew = ! $stock->exists;
        $stock->name = $name;
        $stock->isin = $isin ?: $stock->isin;
        $stock->is_active = true;
        if ($exchange === 'NSE' && \Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'series')) {
            $stock->series = $series ?: ($stock->series ?: 'EQ');
        }
        if ($exchange === 'BSE' && \Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'is_dual_listed')) {
            $stock->is_dual_listed = false;
        }
        if ($exchange === 'BSE' && $bseScripCode && \Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'bse_scrip_code')) {
            $stock->bse_scrip_code = $bseScripCode;
        }
        $this->resolver->applyProviderSymbols($stock);

        if (! $stock->last_verified_at) {
            $stock->last_verified_at = now();
        }

        $stock->save();

        return [
            'action' => $isNew ? 'added' : 'updated',
            'stock' => $stock->fresh(),
        ];
    }

    /**
     * @param  array<int, int>  $stockIds
     * @return array{processed: int, stored_rows: int, failed: int, errors: array<int, string>}
     */
    protected function backfillNewlyAddedStocks(array $stockIds): array
    {
        $stocks = Stock::query()
            ->whereIn('id', $stockIds)
            ->where('is_benchmark', false)
            ->orderBy('id')
            ->get();

        $from = now()->subDays((int) config('portfolio.universe_price_sync.history_days', 365))->startOfDay();
        $to = now()->startOfDay();
        $delayMs = (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400);

        $stats = [
            'processed' => 0,
            'stored_rows' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        PriceSyncNotificationContext::withoutTelegram(function () use ($stocks, $from, $to, $delayMs, &$stats) {
            foreach ($stocks as $index => $stock) {
                $stats['processed']++;
                try {
                    $result = $this->priceFetch->syncStock(
                        $stock,
                        $from,
                        $to,
                        notifyTelegramOnFailure: false,
                    );
                    $stats['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                    if (! ($result['success'] ?? false)) {
                        $stats['failed']++;
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $stock->symbol.': '.implode('; ', $result['errors'] ?? ['sync failed']);
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    if (count($stats['errors']) < 20) {
                        $stats['errors'][] = $stock->symbol.': '.$e->getMessage();
                    }
                }

                if ($delayMs > 0 && $index < $stocks->count() - 1) {
                    usleep($delayMs * 1000);
                }
            }
        });

        return $stats;
    }

    /**
     * @param  array<int, string>  $seenKeys
     */
    protected function deactivateMissing(array $seenKeys, string $exchange): int
    {
        $seenSymbols = collect($seenKeys)
            ->map(fn (string $key) => explode('|', $key)[0])
            ->unique()
            ->values()
            ->all();

        if ($seenSymbols === []) {
            return 0;
        }

        return Stock::query()
            ->where('exchange', $exchange)
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereNotIn('symbol', $seenSymbols)
            ->update(['is_active' => false]);
    }

    protected function downloadCsv(string $url): string
    {
        $response = ExternalHttp::client()
            ->timeout(60)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download stock master CSV: HTTP '.$response->status());
        }

        return (string) $response->body();
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string}>
     */
    protected function downloadCsvRows(string $url): array
    {
        $content = $this->downloadCsv($url);
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        array_shift($lines);

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                continue;
            }
            $rows[] = [
                'symbol' => strtoupper(trim($cols[0])),
                'name' => trim($cols[1]),
                'isin' => isset($cols[2]) ? trim($cols[2]) ?: null : null,
            ];
        }

        return $rows;
    }
}
