<?php

namespace App\Services\Screener;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\ScreenerRunHit;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\EquityUniverseService;
use App\Services\IndexCatalogService;
use App\Services\IndexConstituentService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScreenerRunService
{
    public function __construct(
        protected ScreenerEvaluationService $evaluation,
        protected EquityUniverseService $universe,
        protected TelegramNotificationService $telegram,
        protected IndexConstituentService $indexConstituents,
        protected IndexCatalogService $indexCatalog,
    ) {}

    /**
     * Start a new run and process the first chunk (or all for small scopes).
     *
     * @return array{run:array,continued:bool,completed:bool}
     */
    public function start(Screener $screener, string $triggeredBy = 'manual'): array
    {
        $run = ScreenerRun::query()->create([
            'screener_id' => $screener->id,
            'triggered_by' => in_array($triggeredBy, ['manual', 'schedule'], true) ? $triggeredBy : 'manual',
            'status' => 'running',
            'started_at' => now(),
            'stats_json' => [
                'scanned' => 0,
                'matched' => 0,
                'skipped_insufficient_data' => 0,
                'errors' => 0,
                'cursor' => 0,
                'total' => 0,
                'warnings' => [],
                'telegram_sent' => false,
                'telegram_failed' => false,
            ],
        ]);

        $screener->forceFill(['last_run_at' => now()])->save();

        return $this->processChunk($run->fresh(['screener']));
    }

    /**
     * Continue a running chunked run.
     *
     * @return array{run:array,continued:bool,completed:bool}
     */
    public function continue(ScreenerRun $run): array
    {
        if ($run->status !== 'running') {
            return [
                'run' => $this->formatRun($run->load('screener')),
                'continued' => false,
                'completed' => $run->status === 'completed',
            ];
        }

        return $this->processChunk($run->load('screener'));
    }

    /**
     * @return array{run:array,continued:bool,completed:bool}
     */
    public function processChunk(ScreenerRun $run): array
    {
        $screener = $run->screener;
        if ($screener === null) {
            $this->fail($run, 'Screener missing.');

            return ['run' => $this->formatRun($run), 'continued' => false, 'completed' => false];
        }

        try {
            [$stockIds, $warning] = $this->resolveStockIds($screener);
            $stats = $run->stats_json ?? [];
            if ($warning !== null) {
                $warnings = $stats['warnings'] ?? [];
                if (! in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                    $stats['warnings'] = $warnings;
                }
            }
            $stats['total'] = count($stockIds);
            $cursor = (int) ($stats['cursor'] ?? 0);
            $chunkSize = ScreenerCatalog::CHUNK_SIZE;
            $chunkIds = array_slice($stockIds, $cursor, $chunkSize);

            $definition = is_array($screener->definition_json)
                ? $screener->definition_json
                : ['root' => $screener->definition_json];
            $lookback = $this->evaluation->maxLookback($definition);
            $fetchLimit = $lookback + 5;

            if ($chunkIds === [] && $cursor === 0) {
                // Empty universe
                $stats['scanned'] = 0;
                $stats['matched'] = 0;
                $stats['cursor'] = 0;
                $run->stats_json = $stats;
                $this->complete($run, $screener);

                return [
                    'run' => $this->formatRun($run->fresh()),
                    'continued' => false,
                    'completed' => true,
                ];
            }

            $stocks = Stock::query()
                ->whereIn('id', $chunkIds)
                ->get()
                ->keyBy('id');

            foreach ($chunkIds as $stockId) {
                $stock = $stocks->get($stockId);
                if ($stock === null) {
                    $stats['errors'] = ((int) ($stats['errors'] ?? 0)) + 1;
                    $cursor++;

                    continue;
                }

                try {
                    $bars = $this->loadBars((int) $stockId, $fetchLimit);
                    $result = $this->evaluation->evaluateStock($definition, $bars);
                    $stats['scanned'] = ((int) ($stats['scanned'] ?? 0)) + 1;

                    if ($result['skipped']) {
                        $stats['skipped_insufficient_data'] = ((int) ($stats['skipped_insufficient_data'] ?? 0)) + 1;
                    } elseif ($result['matched']) {
                        $stats['matched'] = ((int) ($stats['matched'] ?? 0)) + 1;
                        ScreenerRunHit::query()->create([
                            'run_id' => $run->id,
                            'stock_id' => $stock->id,
                            'symbol' => $stock->symbol,
                            'exchange' => $stock->exchange,
                            'name' => $stock->name,
                            'metrics_json' => $result['metrics'],
                        ]);
                    }
                } catch (Throwable $e) {
                    $stats['errors'] = ((int) ($stats['errors'] ?? 0)) + 1;
                    Log::warning('Screener stock evaluation failed', [
                        'run_id' => $run->id,
                        'stock_id' => $stockId,
                        'error' => $e->getMessage(),
                    ]);
                }

                $cursor++;
            }

            $stats['cursor'] = $cursor;
            $run->stats_json = $stats;
            $run->save();

            if ($cursor >= count($stockIds)) {
                $this->complete($run, $screener);

                return [
                    'run' => $this->formatRun($run->fresh()),
                    'continued' => false,
                    'completed' => true,
                ];
            }

            return [
                'run' => $this->formatRun($run->fresh()),
                'continued' => true,
                'completed' => false,
            ];
        } catch (Throwable $e) {
            $this->fail($run, $e->getMessage());

            return [
                'run' => $this->formatRun($run->fresh()),
                'continued' => false,
                'completed' => false,
            ];
        }
    }

    public function clearRuns(Screener $screener): int
    {
        return ScreenerRun::query()
            ->where('screener_id', $screener->id)
            ->delete();
    }

    /**
     * Drive a run to completion (scheduler / small scopes).
     */
    public function runToCompletion(Screener $screener, string $triggeredBy = 'manual', int $maxChunks = 500): ScreenerRun
    {
        $result = $this->start($screener, $triggeredBy);
        $runId = $result['run']['id'];
        $chunks = 1;
        while (! ($result['completed'] ?? false) && ($result['continued'] ?? false) && $chunks < $maxChunks) {
            $run = ScreenerRun::query()->findOrFail($runId);
            $result = $this->continue($run);
            $chunks++;
        }

        return ScreenerRun::query()->findOrFail($runId);
    }

    private function complete(ScreenerRun $run, Screener $screener): void
    {
        $run->status = 'completed';
        $run->finished_at = now();
        $stats = $run->stats_json ?? [];

        if ($screener->telegram_enabled && $run->triggered_by === 'schedule') {
            try {
                $sent = $this->sendTelegram($screener, $run);
                $stats['telegram_sent'] = $sent;
                $stats['telegram_failed'] = ! $sent;
            } catch (Throwable $e) {
                $stats['telegram_sent'] = false;
                $stats['telegram_failed'] = true;
                Log::warning('Screener Telegram failed', [
                    'run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $run->stats_json = $stats;
        $run->save();
    }

    private function fail(ScreenerRun $run, string $message): void
    {
        $run->status = 'failed';
        $run->finished_at = now();
        $run->error_message = mb_substr($message, 0, 2000);
        $run->save();
    }

    private function sendTelegram(Screener $screener, ScreenerRun $run): bool
    {
        $profile = PortfolioProfile::query()->find($screener->profile_id);
        if ($profile === null) {
            return false;
        }

        $stats = $run->stats_json ?? [];
        $matched = (int) ($stats['matched'] ?? 0);
        $scanned = (int) ($stats['scanned'] ?? 0);
        $skipped = (int) ($stats['skipped_insufficient_data'] ?? 0);

        $hits = ScreenerRunHit::query()
            ->where('run_id', $run->id)
            ->orderBy('symbol')
            ->limit(ScreenerCatalog::TELEGRAM_HIT_CAP)
            ->get();

        $lines = [
            'Screener: '.$screener->name,
            "Matched: {$matched} / scanned {$scanned} (skipped {$skipped} low history)",
        ];

        foreach ($hits as $hit) {
            $metricBits = [];
            foreach ($hit->metrics_json ?? [] as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $lv = isset($m['left_value']) && is_numeric($m['left_value'])
                    ? round((float) $m['left_value'], 2)
                    : '?';
                $op = ScreenerCatalog::operatorLabel((string) ($m['operator'] ?? ''));
                $weight = isset($m['weight_factor']) && is_numeric($m['weight_factor'])
                    ? (float) $m['weight_factor']
                    : 1.0;
                $rightLabel = (string) ($m['right'] ?? '');
                if (abs($weight - 1.0) > 1e-12) {
                    $rightLabel = rtrim(rtrim(sprintf('%.6F', $weight), '0'), '.').'×'.$rightLabel;
                }
                if (isset($m['right_value']) && is_numeric($m['right_value']) && ($m['right'] ?? '') !== (string) ($m['right_value'] ?? '')) {
                    // show both labels when comparing indicators
                    $metricBits[] = ($m['left'] ?? '').'='.$lv.' '.$op.' '.$rightLabel;
                } else {
                    $metricBits[] = ($m['left'] ?? '').'='.$lv;
                }
            }
            $suffix = $metricBits !== [] ? ' — '.implode(', ', array_slice($metricBits, 0, 3)) : '';
            $ex = $hit->exchange ? " ({$hit->exchange})" : '';
            $lines[] = '• '.$hit->symbol.$ex.$suffix;
        }

        $more = $matched - $hits->count();
        if ($more > 0) {
            $lines[] = "… and {$more} more";
        }

        return $this->telegram->sendMessageForProfile($profile, implode("\n", $lines));
    }

    /**
     * @return array{0:list<int>,1:?string}
     */
    public function resolveStockIds(Screener $screener): array
    {
        $warning = null;

        if ($screener->scope === 'holdings') {
            $ids = Holding::query()
                ->where('profile_id', $screener->profile_id)
                ->where('quantity', '>', 0)
                ->pluck('stock_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return [$ids, null];
        }

        if ($screener->scope === 'watchlist') {
            if (! $screener->watchlist_id) {
                return [[], 'Watchlist missing; empty set.'];
            }
            $wl = Watchlist::query()
                ->where('profile_id', $screener->profile_id)
                ->where('id', $screener->watchlist_id)
                ->first();
            if ($wl === null) {
                return [[], 'Watchlist deleted; empty set.'];
            }
            $ids = WatchlistItem::query()
                ->where('watchlist_id', $wl->id)
                ->pluck('stock_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return [$ids, null];
        }

        if ($screener->scope === 'index') {
            $symbol = strtoupper(trim((string) $screener->index_symbol));
            if ($symbol === '') {
                return [[], 'Index missing; empty set.'];
            }
            $def = $this->indexCatalog->definitionForSymbol($symbol);
            if ($def === null || ! $this->indexCatalog->supportsConstituents($def)) {
                return [[], 'Index not supported for constituents; empty set.'];
            }
            $rows = $this->indexConstituents->constituentsForSymbol($symbol, forceRefresh: false);
            $ids = [];
            foreach ($rows as $row) {
                if (! empty($row['stock_id'])) {
                    $ids[] = (int) $row['stock_id'];
                }
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                return [[], 'Index constituents unavailable; empty set.'];
            }

            return [$ids, null];
        }

        // all_equities
        $ids = $this->universe->universeStockQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [$ids, $warning];
    }

    /**
     * @return list<array{open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close:?float}>
     */
    private function loadBars(int $stockId, int $limit): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stockId)
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
     * @return array<string,mixed>
     */
    public function formatRun(ScreenerRun $run, bool $withHits = false, int $hitPage = 1, int $perPage = 100): array
    {
        $stats = $run->stats_json ?? [];
        $data = [
            'id' => $run->id,
            'screener_id' => $run->screener_id,
            'triggered_by' => $run->triggered_by,
            'status' => $run->status,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'finished_at' => optional($run->finished_at)?->toIso8601String(),
            'stats' => [
                'scanned' => (int) ($stats['scanned'] ?? 0),
                'matched' => (int) ($stats['matched'] ?? 0),
                'skipped_insufficient_data' => (int) ($stats['skipped_insufficient_data'] ?? 0),
                'errors' => (int) ($stats['errors'] ?? 0),
                'cursor' => (int) ($stats['cursor'] ?? 0),
                'total' => (int) ($stats['total'] ?? 0),
                'warnings' => $stats['warnings'] ?? [],
                'telegram_sent' => (bool) ($stats['telegram_sent'] ?? false),
                'telegram_failed' => (bool) ($stats['telegram_failed'] ?? false),
            ],
            'error_message' => $run->error_message,
            'progress_pct' => $this->progressPct($stats),
        ];

        if ($withHits) {
            $paginator = ScreenerRunHit::query()
                ->where('run_id', $run->id)
                ->orderBy('symbol')
                ->paginate($perPage, ['*'], 'page', $hitPage);

            $data['hits'] = [
                'data' => $paginator->getCollection()->map(fn (ScreenerRunHit $h) => [
                    'id' => $h->id,
                    'stock_id' => $h->stock_id,
                    'symbol' => $h->symbol,
                    'exchange' => $h->exchange,
                    'name' => $h->name,
                    'metrics' => $h->metrics_json,
                ])->values(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ];
        }

        return $data;
    }

    /**
     * Stack completed runs (latest N, columns oldest→newest) into a stock×run presence matrix.
     *
     * @return array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,run_count:int,stock_count:int}
     */
    public function compareMatrix(Screener $screener): array
    {
        $latest = ScreenerRun::query()
            ->where('screener_id', $screener->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit(ScreenerCatalog::RUN_HISTORY_UI_LIMIT)
            ->get();

        $runs = $latest->sortBy('id')->values();
        if ($runs->isEmpty()) {
            return [
                'columns' => [],
                'rows' => [],
                'run_count' => 0,
                'stock_count' => 0,
            ];
        }

        $runIds = $runs->pluck('id')->all();
        $runIndex = [];
        foreach ($runs as $i => $run) {
            $runIndex[(int) $run->id] = $i;
        }

        $columns = $runs->map(function (ScreenerRun $run) {
            $stats = $run->stats_json ?? [];
            $when = $run->finished_at ?? $run->started_at;

            return [
                'id' => $run->id,
                'triggered_by' => $run->triggered_by,
                'trigger_label' => $run->triggered_by === 'schedule' ? 'Scheduled' : 'Manual',
                'status' => $run->status,
                'matched' => (int) ($stats['matched'] ?? 0),
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'finished_at' => optional($run->finished_at)?->toIso8601String(),
                'when_label' => $when ? $when->timezone(config('app.timezone'))->format('d M Y, H:i') : '—',
            ];
        })->all();

        $hits = ScreenerRunHit::query()
            ->whereIn('run_id', $runIds)
            ->orderBy('symbol')
            ->get(['run_id', 'stock_id', 'symbol', 'exchange', 'name']);

        /** @var array<string, array{symbol:string,name:?string,exchange:?string,stock_id:?int,presence:list<bool>}> $bySymbol */
        $bySymbol = [];
        $colCount = count($columns);

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
            $idx = $runIndex[(int) $hit->run_id] ?? null;
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
     * @param  array<string,mixed>  $stats
     */
    private function progressPct(array $stats): float
    {
        $total = (int) ($stats['total'] ?? 0);
        if ($total <= 0) {
            return 100.0;
        }
        $cursor = (int) ($stats['cursor'] ?? 0);

        return round(min(100, ($cursor / $total) * 100), 1);
    }
}
