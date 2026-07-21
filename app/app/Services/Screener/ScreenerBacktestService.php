<?php

namespace App\Services\Screener;

use App\Models\Screener;
use App\Models\ScreenerBacktest;
use App\Models\ScreenerBacktestHit;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;
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
        $this->assertBacktestable($screener);
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
                'day_cursor' => 0,
                'day_total' => count($days),
                'days_done' => 0,
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

    public function discardSession(string $sessionToken): int
    {
        $sessionToken = trim($sessionToken);
        if ($sessionToken === '') {
            return 0;
        }

        $ids = ScreenerBacktest::query()
            ->where('session_token', $sessionToken)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        ScreenerBacktestHit::query()->whereIn('backtest_id', $ids)->delete();

        return ScreenerBacktest::query()->whereIn('id', $ids)->delete();
    }

    /**
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
        if (! is_array($dates) || $dates === []) {
            return [
                'columns' => [],
                'rows' => [],
                'run_count' => 0,
                'stock_count' => 0,
            ];
        }

        $dayMatched = [];
        foreach ($stats['days'] ?? [] as $day) {
            if (is_array($day) && isset($day['date'])) {
                $dayMatched[(string) $day['date']] = (int) ($day['matched'] ?? 0);
            }
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
            ->where('backtest_id', $backtest->id)
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
            $asOf = $hit->as_of_date?->toDateString() ?? (string) $hit->getRawOriginal('as_of_date');
            $idx = $dateIndex[$asOf] ?? null;
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
     * @return array{backtest:array,continued:bool,completed:bool}
     */
    private function processChunk(ScreenerBacktest $backtest): array
    {
        $screener = $backtest->screener;
        if ($screener === null) {
            return $this->fail($backtest, 'Screener missing.');
        }

        try {
            $this->assertBacktestable($screener);
            [$stockIds, $warning] = $this->runs->resolveStockIds($screener);
            $stats = $backtest->stats_json ?? [];
            if ($warning !== null) {
                $warnings = $stats['warnings'] ?? [];
                if (! in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                    $stats['warnings'] = $warnings;
                }
            }

            $dates = $stats['as_of_dates'] ?? [];
            if (! is_array($dates)) {
                $dates = [];
            }
            $dayCursor = (int) ($stats['day_cursor'] ?? 0);
            $dayTotal = count($dates);
            $stats['day_total'] = $dayTotal;

            if ($dayTotal === 0) {
                $stats['days_done'] = 0;
                $backtest->stats_json = $stats;
                $backtest->status = 'completed';
                $backtest->save();

                return [
                    'backtest' => $this->format($backtest->fresh()),
                    'continued' => false,
                    'completed' => true,
                ];
            }

            $chunkDates = array_slice($dates, $dayCursor, ScreenerCatalog::BACKTEST_DAY_CHUNK);
            if ($chunkDates === []) {
                $backtest->stats_json = $stats;
                $backtest->status = 'completed';
                $backtest->save();

                return [
                    'backtest' => $this->format($backtest->fresh()),
                    'continued' => false,
                    'completed' => true,
                ];
            }

            $definition = is_array($screener->definition_json)
                ? $screener->definition_json
                : ['root' => $screener->definition_json];
            $lookback = $this->evaluation->maxLookback($definition);
            $fetchLimit = $lookback + 5;

            $entityLookbacks = $this->evaluation->entityLookbacks($definition);
            $entityStockIds = [];
            foreach ($entityLookbacks as $entitySymbol => $entityLookback) {
                $benchmark = $this->runs->benchmarkStockForEntity((string) $entitySymbol);
                if ($benchmark === null) {
                    $warning = "Index {$entitySymbol} has no cached price data; conditions computed on it will not match.";
                    $warnings = $stats['warnings'] ?? [];
                    if (! in_array($warning, $warnings, true)) {
                        $warnings[] = $warning;
                        $stats['warnings'] = $warnings;
                    }

                    continue;
                }
                $entityStockIds[$entitySymbol] = (int) $benchmark->id;
            }

            $stocks = Stock::query()
                ->whereIn('id', $stockIds)
                ->get()
                ->keyBy('id');

            $daysStats = is_array($stats['days'] ?? null) ? $stats['days'] : [];

            foreach ($chunkDates as $dateStr) {
                $asOf = Carbon::parse((string) $dateStr, config('app.timezone'))->toDateString();
                $dayMatched = 0;

                $entityBars = [];
                foreach ($entityLookbacks as $entitySymbol => $entityLookback) {
                    $benchId = $entityStockIds[$entitySymbol] ?? null;
                    $entityBars[$entitySymbol] = $benchId !== null
                        ? $this->loadBarsAsOf($benchId, $asOf, $entityLookback + 5)
                        : [];
                }

                foreach ($stockIds as $stockId) {
                    $stock = $stocks->get($stockId);
                    if ($stock === null) {
                        $stats['errors'] = ((int) ($stats['errors'] ?? 0)) + 1;

                        continue;
                    }

                    try {
                        $bars = $this->loadBarsAsOf((int) $stockId, $asOf, $fetchLimit);
                        $result = $this->evaluation->evaluateStock($definition, $bars, $entityBars);
                        $stats['scanned'] = ((int) ($stats['scanned'] ?? 0)) + 1;

                        if ($result['skipped']) {
                            $stats['skipped_insufficient_data'] = ((int) ($stats['skipped_insufficient_data'] ?? 0)) + 1;
                        } elseif ($result['matched']) {
                            $dayMatched++;
                            $stats['matched'] = ((int) ($stats['matched'] ?? 0)) + 1;
                            ScreenerBacktestHit::query()->create([
                                'backtest_id' => $backtest->id,
                                'as_of_date' => $asOf,
                                'stock_id' => $stock->id,
                                'symbol' => $stock->symbol,
                                'exchange' => $stock->exchange,
                                'name' => $stock->name,
                            ]);
                        }
                    } catch (Throwable $e) {
                        $stats['errors'] = ((int) ($stats['errors'] ?? 0)) + 1;
                        Log::warning('Screener backtest stock evaluation failed', [
                            'backtest_id' => $backtest->id,
                            'stock_id' => $stockId,
                            'as_of' => $asOf,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $daysStats[] = [
                    'date' => $asOf,
                    'matched' => $dayMatched,
                ];
                $dayCursor++;
                $stats['day_cursor'] = $dayCursor;
                $stats['days_done'] = $dayCursor;
                $stats['matched_day_total'] = ((int) ($stats['matched_day_total'] ?? 0)) + $dayMatched;
            }

            $stats['days'] = $daysStats;
            $backtest->stats_json = $stats;
            $backtest->save();

            if ($dayCursor >= $dayTotal) {
                $backtest->status = 'completed';
                $backtest->save();

                return [
                    'backtest' => $this->format($backtest->fresh()),
                    'continued' => false,
                    'completed' => true,
                ];
            }

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
     * @return list<array{open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close:?float}>
     */
    public function loadBarsAsOf(int $stockId, string $asOfDate, int $limit): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stockId)
            ->where('price_date', '<=', $asOfDate)
            ->orderByDesc('price_date')
            ->limit($limit)
            ->get(['open_price', 'high_price', 'low_price', 'close_price', 'adjusted_close_price', 'volume']);

        $bars = [];
        foreach ($rows->reverse()->values() as $row) {
            $bars[] = [
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
                'day_cursor' => (int) ($stats['day_cursor'] ?? 0),
                'day_total' => (int) ($stats['day_total'] ?? 0),
                'days_done' => (int) ($stats['days_done'] ?? 0),
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

    private function assertBacktestable(Screener $screener): void
    {
        if (! in_array($screener->scope, ScreenerCatalog::BACKTEST_SCOPES, true)) {
            throw ValidationException::withMessages([
                'scope' => 'Backtest is only available for holdings and watchlist scopes.',
            ]);
        }
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
