<?php

namespace App\Services\UniversePrice;

use App\Models\IgnoredPriceGap;
use App\Models\Setting;
use App\Models\StockPrice;
use App\Services\Bhavcopy\BhavcopyStockMatcher;
use App\Services\BseBhavcopyService;
use App\Services\NseBhavcopyService;
use App\Services\Operations\ApiFailureRedactor;
use App\Services\PriceFetchService;
use App\Services\StockPriceHistoryService;
use App\Services\SyncLogService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class UniverseDailyBhavcopySyncService
{
    public const METHOD = 'exchange_bhavcopy';
    public const KEY_RECEIPTS = 'universe_price_sync_bulk_receipts_v1';

    public function __construct(
        protected NseBhavcopyService $nse,
        protected BseBhavcopyService $bse,
        protected BhavcopyStockMatcher $matcher,
        protected PriceFetchService $prices,
        protected StockPriceHistoryService $history,
        protected SyncLogService $logs,
        protected ApiFailureRedactor $redactor,
    ) {}

    /** One validated exchange/session download serves the full canonical scope. */
    public function run(
        Collection $stocks,
        Carbon $from,
        Carbon $to,
        string $runId,
        string $jobName,
        array $stats,
        bool $force = false,
        bool $dryRun = false,
    ): array {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        if ($from->diffInDays($to, true) > 31) {
            throw new InvalidArgumentException('Daily bhavcopy recovery is limited to 31 calendar days; use historical backfill for older work.');
        }
        $stockIds = $stocks->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ignored = $stockIds === [] ? collect() : IgnoredPriceGap::query()
            ->whereIn('stock_id', $stockIds)->orderBy('stock_id')->orderBy('gap_from')->orderBy('gap_to')->get();
        $fingerprint = hash('sha256', json_encode([
            'stocks' => $stocks->sortBy('id')->map(fn ($s) => [
                (int) $s->id, $s->exchange, $s->symbol, $s->series, $s->isin, $s->bse_scrip_code,
            ])->values()->all(),
            'identity_evidence_sha256' => $this->matcher->evidenceHash(),
            'ignored' => $ignored->map(fn ($g) => [$g->stock_id, $g->gap_from->toDateString(), $g->gap_to->toDateString()])->all(),
        ], JSON_THROW_ON_ERROR));
        Cache::forget('setting.'.self::KEY_RECEIPTS);
        $storedReceipts = json_decode((string) Setting::getValue(self::KEY_RECEIPTS), true);
        $entries = ! $force && ($storedReceipts['fingerprint'] ?? null) === $fingerprint
            ? ($storedReceipts['entries'] ?? []) : [];
        $entries = array_filter($entries, fn ($v) => is_array($v)
            && ($v['date'] ?? '') >= $from->toDateString() && ($v['date'] ?? '') <= $to->toDateString());
        $ignoredByStock = $ignored->groupBy('stock_id');
        $written = $conflicted = [];
        $stats['collection_method'] = self::METHOD;
        $stats['bulk'] = [
            'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'dry_run' => $dryRun, 'files_downloaded' => 0, 'file_cache_hits' => 0,
            'file_errors' => 0, 'identity_conflicts' => 0, 'projected_rows' => 0,
            'historical_identity_rows' => 0, 'historical_adjustment_required' => 0,
            'files' => [], 'unavailable_stocks' => 0, 'unavailable_samples' => [],
        ];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! TradingCalendar::isEquitySessionDate($day)) {
                continue;
            }
            foreach ($stocks->groupBy('exchange') as $exchange => $exchangeStocks) {
                if (! in_array($exchange, ['NSE', 'BSE'], true)) {
                    throw new InvalidArgumentException('Unsupported daily bhavcopy exchange.');
                }
                $key = $exchange.':'.$day->toDateString();
                $coverage = $this->coverage($exchangeStocks->pluck('id')->all(), $day);
                $coverageHash = $this->coverageHash($coverage);
                $receipt = $entries[$key] ?? null;
                if (! $force && is_array($receipt) && ($receipt['coverage_hash'] ?? null) === $coverageHash) {
                    $stats['bulk']['file_cache_hits']++;
                    $stats['bulk']['historical_identity_rows'] += $receipt['historical_identity_rows'] ?? 0;
                    $stats['bulk']['files'][] = array_merge($receipt, ['status' => 'receipt_cached']);
                    continue;
                }
                $file = ['exchange' => $exchange, 'date' => $day->toDateString(), 'status' => 'failed'];
                try {
                    $stats['bulk']['files_downloaded']++;
                    $data = ($exchange === 'NSE' ? $this->nse : $this->bse)->fetchDailyRows($day);
                    $matched = $this->matcher->match($exchangeStocks, $data['rows'], $exchange);
                    $historicalBlocked = 0;
                    foreach ($matched['historical_stock_ids'] as $id) {
                        if (! isset($coverage[$id]) && ! $this->isIgnored($ignoredByStock->get($id, collect()), $day)) {
                            // Source bars predate a split. Identity evidence is
                            // not authority to mix raw bars into adjusted history.
                            $matched['conflicts'][$id] = 'historical_adjustment_required';
                            $historicalBlocked++;
                            unset($matched['rows_by_stock_id'][$id]);
                        }
                    }
                    $historicalAccepted = count($matched['historical_stock_ids']) - $historicalBlocked;
                    $stats['bulk']['historical_identity_rows'] += $historicalAccepted;
                    $stats['bulk']['historical_adjustment_required'] += $historicalBlocked;
                    $file += [
                        'source_rows' => $data['source_rows'], 'invalid_rows' => $data['invalid_rows'],
                        'matched_rows' => count($matched['rows_by_stock_id']),
                        'historical_identity_rows' => $historicalAccepted,
                        'historical_adjustment_required' => $historicalBlocked,
                        'identity_conflicts' => count($matched['conflicts']),
                    ];
                    $conflicted += $matched['conflicts'];
                    $stats['bulk']['identity_conflicts'] += count($matched['conflicts']);
                    $fileStored = 0;
                    foreach ($exchangeStocks as $stock) {
                        $id = (int) $stock->id;
                        if (isset($coverage[$id]) || ! isset($matched['rows_by_stock_id'][$id])
                            || $this->isIgnored($ignoredByStock->get($id, collect()), $day)) {
                            continue;
                        }
                        $stats['bulk']['projected_rows']++;
                        if ($dryRun) {
                            continue;
                        }
                        $count = $this->prices->storeHistoricalRows(
                            $stock, [$matched['rows_by_stock_id'][$id]], strtolower($exchange).'_bhavcopy', true,
                        );
                        $fileStored += $count;
                        $written[$id] = ($written[$id] ?? 0) + $count;
                        $stats['stored_rows'] += $count;
                    }
                    $file['status'] = $dryRun ? 'preview' : ($matched['conflicts'] === [] ? 'applied' : 'identity_conflict');
                    $file['stored_rows'] = $fileStored;
                    if (! $dryRun && $matched['conflicts'] === []) {
                        $file['coverage_hash'] = $this->coverageHash($this->coverage($exchangeStocks->pluck('id')->all(), $day));
                        $file['checked_at'] = now()->toIso8601String();
                        $entries[$key] = $file;
                        Setting::setValue(self::KEY_RECEIPTS, json_encode([
                            'fingerprint' => $fingerprint, 'entries' => $entries,
                        ], JSON_THROW_ON_ERROR));
                    }
                } catch (\Throwable $e) {
                    $stats['bulk']['file_errors']++;
                    $file['error'] = $this->redactor->message($e->getMessage());
                    if (preg_match('/\bHTTP\s+429\b/i', (string) $file['error']) === 1) {
                        $stats['rate_limit_hits']++;
                    }
                    if (count($stats['errors']) < 20) {
                        $stats['errors'][] = $key.': '.$file['error'];
                    }
                }
                $stats['bulk']['files'][] = $file;
                if (! $dryRun) {
                    $this->logs->log($runId, $jobName, $file['status'] === 'applied' ? 'info' : 'warning',
                        'Universe daily bhavcopy file checked', $file);
                }
            }
        }
        foreach ($stocks as $stock) {
            $id = (int) $stock->id;
            $stats['processed']++;
            $remaining = $this->history->getMissingHistoryRanges($stock, $from, $to);
            if ($remaining === []) {
                $stats['succeeded']++;
                if (($written[$id] ?? 0) === 0) {
                    $stats['cache_hits']++;
                }
                continue;
            }
            $stats['failed']++;
            $stats['bulk']['unavailable_stocks']++;
            if (count($stats['bulk']['unavailable_samples']) < 20) {
                $stats['bulk']['unavailable_samples'][] = [
                    'symbol' => $stock->symbol, 'exchange' => $stock->exchange,
                    'reason' => $conflicted[$id] ?? 'required_history_unavailable',
                    'ranges' => array_map(fn ($r) => ['from' => $r['from']->toDateString(), 'to' => $r['to']->toDateString()], $remaining),
                ];
            }
            if (count($stats['errors']) < 20) {
                $stats['errors'][] = $stock->symbol.': required sessions remain unavailable after exchange bhavcopy collection';
            }
        }

        return ['stats' => $stats, 'last_stock_id' => 0];
    }

    /** Read-only publication/identity/coverage plan; never writes prices, receipts or run logs. */
    public function preview(Collection $stocks, Carbon $from, Carbon $to): array
    {
        return $this->run($stocks, $from, $to, '', '', [
            'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0,
            'stored_rows' => 0, 'cache_hits' => 0, 'rate_limit_hits' => 0, 'errors' => [],
        ], force: true, dryRun: true)['stats'];
    }

    private function coverage(array $ids, Carbon $day): array
    {
        $present = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (StockPrice::query()->whereIn('stock_id', $chunk)->where('price_date', $day->copy()->startOfDay())->pluck('stock_id') as $id) {
                $present[(int) $id] = true;
            }
        }

        return $present;
    }

    private function coverageHash(array $coverage): string
    {
        $ids = array_keys($coverage);
        sort($ids, SORT_NUMERIC);

        return hash('sha256', implode(',', $ids));
    }

    private function isIgnored(Collection $ranges, Carbon $day): bool
    {
        foreach ($ranges as $gap) {
            if ($day->betweenIncluded($gap->gap_from->copy()->startOfDay(), $gap->gap_to->copy()->endOfDay())) {
                return true;
            }
        }

        return false;
    }
}
