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
    ) {}

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
     *   backfill_skipped: bool
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
     *   backfill_skipped: bool
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

        if ($dualListedIsins === []) {
            return;
        }

        Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->whereIn('isin', array_keys($dualListedIsins))
            ->update(['is_dual_listed' => true]);
    }

    /**
     * @param  array<int, string>  $nseIsins
     */
    protected function deactivateBseDuplicatesOfNse(array $nseIsins): int
    {
        if ($nseIsins === []) {
            return 0;
        }

        return Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereIn('isin', $nseIsins)
            ->update(['is_active' => false]);
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

        $header = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($header as $index => $column) {
            $headerMap[strtoupper(trim($column))] = $index;
        }

        $symbolIdx = $headerMap['SYMBOL'] ?? 0;
        $nameIdx = $headerMap['NAME OF COMPANY'] ?? 1;
        $seriesIdx = $headerMap['SERIES'] ?? 2;
        $isinIdx = $headerMap['ISIN NUMBER'] ?? $headerMap['ISIN'] ?? null;

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $series = strtoupper(trim($cols[$seriesIdx] ?? 'EQ'));
            if ($series !== 'EQ') {
                continue;
            }

            $symbol = strtoupper(trim($cols[$symbolIdx] ?? ''));
            if ($symbol === '' || $this->resolver->isMalformed($symbol)) {
                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'name' => trim($cols[$nameIdx] ?? $symbol),
                'isin' => $isinIdx !== null ? trim($cols[$isinIdx] ?? '') ?: null : null,
                'series' => $series,
            ];
        }

        return $rows;
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
        if ($exchange === 'BSE' && \Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'is_dual_listed')) {
            $stock->is_dual_listed = false;
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
