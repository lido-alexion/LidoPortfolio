<?php

namespace App\Services\Screener;

use App\Models\Screener;
use App\Models\ScreenerBacktest;
use App\Models\ScreenerBacktestDay;
use App\Models\ScreenerBacktestHit;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScreenerBacktestService
{
    public function __construct(
        protected ScreenerEvaluationService $evaluation,
        protected ScreenerRunService $runs,
    ) {}

    /**
     * @return array{backtest:array,continued:bool,completed:bool}
     */
    public function start(Screener $screener, string $rangeKey, string $sessionToken): array
    {
        $rangeKey = $this->normalizeRangeKey($rangeKey);
        $sessionToken = $this->normalizeSessionToken($sessionToken);

        $this->discardSession($sessionToken);

        $to = Carbon::now(config('app.timezone'))->startOfDay();
        $from = $this->fromDateForRange($rangeKey, $to->copy());
        $days = $this->weekdayDates($from, $to);

        $backtest = ScreenerBacktest::query()->create([
            'screener_id' => $screener->id,
            'profile_id' => $screener->profile_id,
            'session_token' => $sessionToken,
            'range_key' => $rangeKey,
            'status' => 'running',
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'stats_json' => [
                'day_total' => count($days),
                'days_done' => 0,
                'days_reused' => 0,
                'matched_day_total' => 0,
                'scanned' => 0,
                'matched' => 0,
                'skipped_insufficient_data' => 0,
                'errors' => 0,
                'days' => [],
                'warnings' => [],
                'as_of_dates' => array_map(static fn (Carbon $d) => $d->toDateString(), $days),
            ],
        ]);

        return $this->processChunk($backtest->fresh(['screener']));
    }

    /**
     * @return array{backtest:array,continued:bool,completed:bool}
     */
    public function continue(ScreenerBacktest $backtest): array
    {
        if ($backtest->status !== 'running') {
            return [
                'backtest' => $this->format($backtest),
                'continued' => false,
                'completed' => $backtest->status === 'completed',
            ];
        }

        return $this->processChunk($backtest->load('screener'));
    }

    /**
     * Drop transient job rows for a session. Per-date results (days + hits)
     * are persistent and survive; use clearResults() to wipe them.
     */
    public function discardSession(string $sessionToken): int
    {
        $sessionToken = trim($sessionToken);
        if ($sessionToken === '') {
            return 0;
        }

        return ScreenerBacktest::query()
            ->where('session_token', $sessionToken)
            ->delete();
    }

    /**
     * Delete all persisted per-date backtest results for a screener.
     * Returns the number of cached dates removed.
     */
    public function clearResults(Screener $screener): int
    {
        ScreenerBacktestHit::query()->where('screener_id', $screener->id)->delete();

        return ScreenerBacktestDay::query()->where('screener_id', $screener->id)->delete();
    }

    /**
     * Matrix for a backtest job: its date window read from the persistent per-date cache.
     *
     * @return array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,run_count:int,stock_count:int}
     */
    public function matrix(ScreenerBacktest $backtest): array
    {
        if ($backtest->status !== 'completed') {
            throw ValidationException::withMessages([
                'backtest' => 'Backtest is not completed yet.',
            ]);
        }

        $stats = $backtest->stats_json ?? [];
        $dates = $stats['as_of_dates'] ?? [];
        $dates = is_array($dates) ? array_map('strval', array_values($dates)) : [];

        return $this->matrixForDates((int) $backtest->screener_id, $dates);
    }

    /**
     * Matrix over all persisted backtest dates of a screener (editor display on load).
     *
     * @return array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,run_count:int,stock_count:int}
     */
    public function matrixForScreener(Screener $screener): array
    {
        $dates = ScreenerBacktestDay::query()
            ->where('screener_id', $screener->id)
            ->orderBy('as_of_date')
            ->pluck('as_of_date')
            ->map(fn ($d) => $this->dateKey($d))
            ->all();

        return $this->matrixForDates((int) $screener->id, $dates);
    }

    /**
     * @param  list<string>  $dates  Chronological as-of dates (Y-m-d).
     * @return array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,run_count:int,stock_count:int}
     */
    private function matrixForDates(int $screenerId, array $dates): array
    {
        if ($dates === []) {
            return [
                'columns' => [],
                'rows' => [],
                'run_count' => 0,
                'stock_count' => 0,
            ];
        }

        $dayMatched = [];
        $dayRows = ScreenerBacktestDay::query()
            ->where('screener_id', $screenerId)
            ->whereIn('as_of_date', $dates)
            ->get(['as_of_date', 'matched']);
        foreach ($dayRows as $day) {
            $dayMatched[$this->dateKey($day->as_of_date)] = (int) $day->matched;
        }

        $dateIndex = [];
        $columns = [];
        foreach (array_values($dates) as $i => $date) {
            $dateStr = (string) $date;
            $dateIndex[$dateStr] = $i;
            $matched = $dayMatched[$dateStr] ?? 0;
            $when = Carbon::parse($dateStr, config('app.timezone'));
            $columns[] = [
                'id' => $dateStr,
                'triggered_by' => 'backtest',
                'trigger_label' => 'Backtest',
                'status' => 'completed',
                'matched' => $matched,
                'started_at' => null,
                'finished_at' => null,
                'when_label' => $when->format('d F Y'),
                'header_label' => $when->format('d F Y').' ('.$matched.')',
            ];
        }

        $colCount = count($columns);
        $hits = ScreenerBacktestHit::query()
            ->where('screener_id', $screenerId)
            ->whereIn('as_of_date', $dates)
            ->orderBy('symbol')
            ->get(['as_of_date', 'stock_id', 'symbol', 'exchange', 'name']);

        /** @var array<string, array{symbol:string,name:?string,exchange:?string,stock_id:?int,presence:list<bool>}> $bySymbol */
        $bySymbol = [];
        foreach ($hits as $hit) {
            $symbol = strtoupper(trim((string) $hit->symbol));
            if ($symbol === '') {
                continue;
            }
            if (! isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = [
                    'symbol' => $symbol,
                    'name' => $hit->name,
                    'exchange' => $hit->exchange,
                    'stock_id' => $hit->stock_id !== null ? (int) $hit->stock_id : null,
                    'presence' => array_fill(0, $colCount, false),
                ];
            }
            $idx = $dateIndex[$this->dateKey($hit->as_of_date)] ?? null;
            if ($idx !== null) {
                $bySymbol[$symbol]['presence'][$idx] = true;
            }
        }

        $rows = array_values($bySymbol);
        usort($rows, static function (array $a, array $b): int {
            $countA = count(array_filter($a['presence']));
            $countB = count(array_filter($b['presence']));
            if ($countA !== $countB) {
                return $countB <=> $countA;
            }

            return strcmp($a['symbol'], $b['symbol']);
        });

        return [
            'columns' => $columns,
            'rows' => $rows,
            'run_count' => count($columns),
            'stock_count' => count($rows),
        ];
    }

    /**
     * Stock-major backtest chunk: each request evaluates a slice of stocks over
     * ALL dates that still need computing. Per stock, bars are loaded once with
     * a single query and indicator series are computed once; each as-of date is
     * then answered by indexing into those series. Dates already cached in
     * portfolio_screener_backtest_days are reused without any computation.
     * Per-date day rows are finalized only when every stock has been processed,
     * so a crashed job never leaves a date looking complete.
     *
     * @return array{backtest:array,continued:bool,completed:bool}
     */
    private function processChunk(ScreenerBacktest $backtest): array
    {
        $screener = $backtest->screener;
        if ($screener === null) {
            return $this->fail($backtest, 'Screener missing.');
        }

        try {
            [$stockIds, $warning] = $this->runs->resolveStockIds($screener);
            $stockIds = array_values($stockIds);
            $stats = $backtest->stats_json ?? [];
            if ($warning !== null) {
                $stats = $this->addWarning($stats, $warning);
            }

            $dates = $stats['as_of_dates'] ?? [];
            $dates = is_array($dates) ? array_map('strval', array_values($dates)) : [];
            $stats['day_total'] = count($dates);

            // First request: pin the set of dates that need computing and clean up
            // hits left behind by a crashed earlier attempt (hits without day rows).
            if (! array_key_exists('missing_dates', $stats)) {
                $cachedDates = $dates === [] ? [] : ScreenerBacktestDay::query()
                    ->where('screener_id', $screener->id)
                    ->whereIn('as_of_date', $dates)
                    ->pluck('as_of_date')
                    ->map(fn ($d) => $this->dateKey($d))
                    ->all();
                $missing = array_values(array_diff($dates, $cachedDates));
                $stats['missing_dates'] = $missing;
                $stats['days_reused'] = count($dates) - count($missing);
                $stats['stock_total'] = count($stockIds);
                $stats['stock_cursor'] = 0;
                $stats['day_agg'] = [];
                if ($missing !== []) {
                    ScreenerBacktestHit::query()
                        ->where('screener_id', $screener->id)
                        ->whereIn('as_of_date', $missing)
                        ->delete();
                }
            }

            $missing = array_map('strval', is_array($stats['missing_dates'] ?? null) ? $stats['missing_dates'] : []);

            if ($dates === [] || $missing === [] || $stockIds === []) {
                return $this->finalize($backtest, $screener->id, $stats, $dates, $missing);
            }

            $definition = is_array($screener->definition_json)
                ? $screener->definition_json
                : ['root' => $screener->definition_json];
            $stockLookback = $this->evaluation->stockLookback($definition);
            // Enough history for the oldest as-of date's lookback plus the full range.
            $barsLimit = $stockLookback + count($dates) + 10;
            $toDate = $missing[count($missing) - 1];

            // Index entity bars are shared by every stock; load them once per request.
            $entityBars = [];
            foreach ($this->evaluation->entityLookbacks($definition) as $entitySymbol => $entityLookback) {
                $benchmark = $this->runs->benchmarkStockForEntity((string) $entitySymbol);
                if ($benchmark === null) {
                    $stats = $this->addWarning($stats, "Index {$entitySymbol} has no cached price data; conditions computed on it will not match.");
                    $entityBars[(string) $entitySymbol] = [];

                    continue;
                }
                $entityBars[(string) $entitySymbol] = $this->loadBarsWithDates((int) $benchmark->id, $toDate, $entityLookback + count($dates) + 10);
            }

            $cursor = (int) ($stats['stock_cursor'] ?? 0);
            $stockTotal = max(1, (int) ($stats['stock_total'] ?? count($stockIds)));
            $chunkIds = array_slice($stockIds, $cursor, ScreenerCatalog::BACKTEST_STOCK_CHUNK);
            $stocks = Stock::query()->whereIn('id', $chunkIds)->get()->keyBy('id');
            $dayAgg = is_array($stats['day_agg'] ?? null) ? $stats['day_agg'] : [];

            foreach ($chunkIds as $stockId) {
                $cursor++;
                $stock = $stocks->get($stockId);
                if ($stock === null) {
                    $stats['errors'] = ((int) ($stats['errors'] ?? 0)) + 1;

                    continue;
                }

                try {
                    $bars = $this->loadBarsWithDates((int) $stockId, $toDate, $barsLimit);
                    $results = $this->evaluation->evaluateAcrossDates($definition, $bars, $missing, $entityBars);

                    $hitRows = [];
                    $now = now();
                    foreach ($results as $asOf => $result) {
                        $agg = $dayAgg[$asOf] ?? ['scanned' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => 0];
                        $agg['scanned']++;
                        $stats['scanned'] = ((int) ($stats['scanned'] ?? 0)) + 1;
                        if ($result['skipped']) {
                            $agg['skipped']++;
                            $stats['skipped_insufficient_data'] = ((int) ($stats['skipped_insufficient_data'] ?? 0)) + 1;
                        } elseif ($result['matched']) {
                            $agg['matched']++;
                            $stats['matched'] = ((int) ($stats['matched'] ?? 0)) + 1;
                            $hitRows[] = [
                                'screener_id' => $screener->id,
                                'as_of_date' => $asOf,
                                'stock_id' => $stock->id,
                                'symbol' => $stock->symbol,
                                'exchange' => $stock->exchange,
                                'name' => $stock->name,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                        $dayAgg[$asOf] = $agg;
                    }

                    foreach (array_chunk($hitRows, 500) as $slice) {
                        ScreenerBacktestHit::query()->insert($slice);
                    }
                } catch (Throwable $e) {
                    $stats['errors'] = ((int) ($stats['errors'] ?? 0)) + 1;
                    Log::warning('Screener backtest stock evaluation failed', [
                        'backtest_id' => $backtest->id,
                        'stock_id' => $stockId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $stats['stock_cursor'] = $cursor;
            $stats['day_agg'] = $dayAgg;
            // Progress reported in day terms for the UI: reused days count as done,
            // missing days advance with the stock cursor.
            $fraction = min(1.0, $cursor / $stockTotal);
            $stats['days_done'] = (int) round(((int) ($stats['days_reused'] ?? 0)) + (count($missing) * $fraction));

            if ($cursor >= (int) ($stats['stock_total'] ?? count($stockIds))) {
                return $this->finalize($backtest, $screener->id, $stats, $dates, $missing);
            }

            $backtest->stats_json = $stats;
            $backtest->save();

            return [
                'backtest' => $this->format($backtest->fresh()),
                'continued' => true,
                'completed' => false,
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail($backtest, $e->getMessage());
        }
    }

    /**
     * Write day rows for every freshly computed date and mark the job completed.
     *
     * @param  array<string,mixed>  $stats
     * @param  list<string>  $dates
     * @param  list<string>  $missing
     * @return array{backtest:array,continued:bool,completed:bool}
     */
    private function finalize(ScreenerBacktest $backtest, int $screenerId, array $stats, array $dates, array $missing): array
    {
        $dayAgg = is_array($stats['day_agg'] ?? null) ? $stats['day_agg'] : [];
        $daysStats = is_array($stats['days'] ?? null) ? $stats['days'] : [];

        foreach ($missing as $asOf) {
            $agg = $dayAgg[$asOf] ?? ['scanned' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => 0];
            ScreenerBacktestDay::query()->updateOrCreate(
                ['screener_id' => $screenerId, 'as_of_date' => $asOf],
                [
                    'scanned' => (int) ($agg['scanned'] ?? 0),
                    'matched' => (int) ($agg['matched'] ?? 0),
                    'skipped_insufficient_data' => (int) ($agg['skipped'] ?? 0),
                    'errors' => (int) ($agg['errors'] ?? 0),
                ],
            );
            $matched = (int) ($agg['matched'] ?? 0);
            $stats['matched_day_total'] = ((int) ($stats['matched_day_total'] ?? 0)) + $matched;
            $daysStats[] = ['date' => $asOf, 'matched' => $matched];
        }

        $stats['days'] = $daysStats;
        $stats['days_done'] = count($dates);
        unset($stats['day_agg']);

        $backtest->stats_json = $stats;
        $backtest->status = 'completed';
        $backtest->save();

        return [
            'backtest' => $this->format($backtest->fresh()),
            'continued' => false,
            'completed' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<string,mixed>
     */
    private function addWarning(array $stats, string $warning): array
    {
        $warnings = is_array($stats['warnings'] ?? null) ? $stats['warnings'] : [];
        if (! in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
            $stats['warnings'] = $warnings;
        }

        return $stats;
    }

    /**
     * All bars up to a date in one lightweight query (no Eloquent hydration),
     * chronological, each carrying its price date for series-index alignment.
     *
     * @return list<array{date:string,open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close:?float}>
     */
    public function loadBarsWithDates(int $stockId, string $toDate, int $limit): array
    {
        $table = (new StockPrice)->getTable();
        $rows = DB::select(
            "select price_date, open_price, high_price, low_price, close_price, adjusted_close_price, volume
             from {$table}
             where stock_id = ? and price_date <= ?
             order by price_date desc
             limit ".max(1, (int) $limit),
            [$stockId, $toDate],
        );

        $bars = [];
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            $bars[] = [
                'date' => substr((string) $row->price_date, 0, 10),
                'open' => $row->open_price !== null ? (float) $row->open_price : null,
                'high' => $row->high_price !== null ? (float) $row->high_price : null,
                'low' => $row->low_price !== null ? (float) $row->low_price : null,
                'close' => $row->close_price !== null ? (float) $row->close_price : null,
                'adjusted_close' => $row->adjusted_close_price !== null ? (float) $row->adjusted_close_price : null,
                'volume' => $row->volume !== null ? (float) $row->volume : null,
            ];
        }

        return $bars;
    }

    /**
     * Normalize a DB as_of_date value (string or Carbon, with or without time) to Y-m-d.
     */
    private function dateKey(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * @return list<Carbon>
     */
    public function weekdayDates(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    public function fromDateForRange(string $rangeKey, Carbon $to): Carbon
    {
        return match ($rangeKey) {
            '6m' => $to->copy()->subMonthsNoOverflow(6),
            '3m' => $to->copy()->subMonthsNoOverflow(3),
            '1m' => $to->copy()->subMonthsNoOverflow(1),
            '15d' => $to->copy()->subDays(14),
            default => $to->copy()->subYearNoOverflow(),
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function format(ScreenerBacktest $backtest): array
    {
        $stats = $backtest->stats_json ?? [];

        return [
            'id' => $backtest->id,
            'screener_id' => $backtest->screener_id,
            'session_token' => $backtest->session_token,
            'range_key' => $backtest->range_key,
            'status' => $backtest->status,
            'from_date' => optional($backtest->from_date)?->toDateString(),
            'to_date' => optional($backtest->to_date)?->toDateString(),
            'error_message' => $backtest->error_message,
            'stats' => [
                'day_total' => (int) ($stats['day_total'] ?? 0),
                'stock_cursor' => (int) ($stats['stock_cursor'] ?? 0),
                'stock_total' => (int) ($stats['stock_total'] ?? 0),
                'days_done' => (int) ($stats['days_done'] ?? 0),
                'days_reused' => (int) ($stats['days_reused'] ?? 0),
                'matched_day_total' => (int) ($stats['matched_day_total'] ?? 0),
                'scanned' => (int) ($stats['scanned'] ?? 0),
                'matched' => (int) ($stats['matched'] ?? 0),
                'skipped_insufficient_data' => (int) ($stats['skipped_insufficient_data'] ?? 0),
                'errors' => (int) ($stats['errors'] ?? 0),
                'warnings' => $stats['warnings'] ?? [],
                'progress_pct' => $this->progressPct($stats),
            ],
            'created_at' => optional($backtest->created_at)?->toIso8601String(),
        ];
    }

    private function normalizeRangeKey(string $rangeKey): string
    {
        $rangeKey = strtolower(trim($rangeKey));
        $allowed = array_column(ScreenerCatalog::BACKTEST_RANGES, 'id');
        if (! in_array($rangeKey, $allowed, true)) {
            throw ValidationException::withMessages([
                'range' => 'Invalid backtest range.',
            ]);
        }

        return $rangeKey;
    }

    private function normalizeSessionToken(string $sessionToken): string
    {
        $sessionToken = trim($sessionToken);
        if ($sessionToken === '' || strlen($sessionToken) > 64) {
            throw ValidationException::withMessages([
                'session_token' => 'session_token is required.',
            ]);
        }

        return $sessionToken;
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function progressPct(array $stats): float
    {
        $total = (int) ($stats['day_total'] ?? 0);
        if ($total <= 0) {
            return 100.0;
        }
        $done = (int) ($stats['days_done'] ?? $stats['day_cursor'] ?? 0);

        return round(min(100, max(0, ($done / $total) * 100)), 1);
    }

    /**
     * @return array{backtest:array,continued:bool,completed:bool}
     */
    private function fail(ScreenerBacktest $backtest, string $message): array
    {
        $backtest->status = 'failed';
        $backtest->error_message = $message;
        $backtest->save();

        return [
            'backtest' => $this->format($backtest->fresh()),
            'continued' => false,
            'completed' => false,
        ];
    }
}
