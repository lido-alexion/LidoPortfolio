<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestRunHit;
use App\Models\Screener;
use App\Services\Screener\ScreenerBacktestService;
use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\ScreenerRunService;
use Illuminate\Support\Facades\DB;

/**
 * Precomputes entry/exit screener hits for all trading days (stock-major chunks).
 * Hits live in portfolio_backtest_run_hits and are deleted when the run finishes.
 */
class EligibilityPrecomputeService
{
    public function __construct(
        protected ScreenerEvaluationService $evaluation,
        protected ScreenerRunService $runs,
        protected ScreenerBacktestService $screenerBacktest,
    ) {}

    /**
     * Advance eligibility precompute within a time budget.
     *
     * @return array{done: bool, context: SimulationContext}
     */
    public function advance(BacktestRun $run, SimulationContext $ctx, float $deadline): array
    {
        $eligibility = is_array($ctx->get('eligibility')) ? $ctx->get('eligibility') : [];
        $screeners = is_array($eligibility['screeners'] ?? null) ? $eligibility['screeners'] : [];

        if (($eligibility['phase'] ?? 'pending') === 'pending') {
            $eligibility['phase'] = 'running';
            $ctx->set('eligibility', $eligibility);
        }

        if ($screeners === []) {
            $eligibility['phase'] = 'done';
            $ctx->set('eligibility', $eligibility);

            return ['done' => true, 'context' => $ctx];
        }

        foreach ($screeners as $idx => $job) {
            if (! empty($job['done'])) {
                continue;
            }
            $result = $this->processScreenerChunk($run, $ctx, $job, $deadline);
            $screeners[$idx] = $result['job'];
            $eligibility['screeners'] = $screeners;
            $ctx->set('eligibility', $eligibility);

            if (! $result['job']['done'] || microtime(true) >= $deadline) {
                return ['done' => false, 'context' => $ctx];
            }
        }

        $allDone = true;
        foreach ($screeners as $job) {
            if (empty($job['done'])) {
                $allDone = false;
                break;
            }
        }
        if ($allDone) {
            $eligibility['phase'] = 'done';
            $eligibility['screeners'] = $screeners;
            $ctx->set('eligibility', $eligibility);

            return ['done' => true, 'context' => $ctx];
        }

        return ['done' => false, 'context' => $ctx];
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{job: array<string, mixed>}
     */
    private function processScreenerChunk(BacktestRun $run, SimulationContext $ctx, array $job, float $deadline): array
    {
        $screenerId = (int) ($job['screener_id'] ?? 0);
        $role = (string) ($job['role'] ?? BacktestRunHit::ROLE_ENTRY);
        $screener = Screener::query()->find($screenerId);
        if ($screener === null) {
            $job['done'] = true;
            $ctx->addWarning("Screener #{$screenerId} missing during eligibility precompute.");

            return ['job' => $job];
        }

        $dates = $ctx->tradingDays();
        if ($dates === []) {
            $job['done'] = true;

            return ['job' => $job];
        }

        if (! isset($job['stock_ids'])) {
            [$stockIds] = $this->runs->resolveStockIds($screener);
            $job['stock_ids'] = array_values($stockIds);
            $job['stock_total'] = count($job['stock_ids']);
            $job['stock_cursor'] = 0;
            BacktestRunHit::query()
                ->where('backtest_run_id', $run->id)
                ->where('screener_id', $screenerId)
                ->where('role', $role)
                ->delete();
        }

        $stockIds = array_values(is_array($job['stock_ids']) ? $job['stock_ids'] : []);
        $cursor = (int) ($job['stock_cursor'] ?? 0);
        if ($cursor >= count($stockIds)) {
            $job['done'] = true;

            return ['job' => $job];
        }

        $definition = is_array($screener->definition_json)
            ? $screener->definition_json
            : ['root' => $screener->definition_json];
        $stockLookback = $this->evaluation->stockLookback($definition);
        $barsLimit = $stockLookback + count($dates) + 10;
        $toDate = $dates[count($dates) - 1];

        $entityBars = [];
        foreach ($this->evaluation->entityLookbacks($definition) as $entitySymbol => $entityLookback) {
            $benchmark = $this->runs->benchmarkStockForEntity((string) $entitySymbol);
            if ($benchmark === null) {
                $entityBars[(string) $entitySymbol] = [];

                continue;
            }
            $entityBars[(string) $entitySymbol] = $this->screenerBacktest->loadBarsWithDates(
                (int) $benchmark->id,
                $toDate,
                $entityLookback + count($dates) + 10
            );
        }

        $chunkSize = ScreenerCatalog::BACKTEST_STOCK_CHUNK;
        while ($cursor < count($stockIds) && microtime(true) < $deadline) {
            $chunkIds = array_slice($stockIds, $cursor, min(25, $chunkSize));
            if ($chunkIds === []) {
                break;
            }
            foreach ($chunkIds as $stockId) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                $cursor++;
                $bars = $this->screenerBacktest->loadBarsWithDates((int) $stockId, $toDate, $barsLimit);
                $results = $this->evaluation->evaluateAcrossDates($definition, $bars, $dates, $entityBars);
                $hitRows = [];
                $now = now();
                foreach ($results as $asOf => $result) {
                    if (! empty($result['matched']) && empty($result['skipped'])) {
                        $hitRows[] = [
                            'backtest_run_id' => $run->id,
                            'screener_id' => $screenerId,
                            'role' => $role,
                            'as_of_date' => $asOf,
                            'stock_id' => (int) $stockId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($hitRows !== []) {
                    foreach (array_chunk($hitRows, 500) as $chunk) {
                        DB::table('portfolio_backtest_run_hits')->insert($chunk);
                    }
                }
            }
            $job['stock_cursor'] = $cursor;
        }

        $job['stock_cursor'] = $cursor;
        if ($cursor >= count($stockIds)) {
            $job['done'] = true;
        }

        return ['job' => $job];
    }

    /**
     * @return list<int>
     */
    public function entryHitsForDate(BacktestRun $run, string $asOfDate): array
    {
        return BacktestRunHit::query()
            ->where('backtest_run_id', $run->id)
            ->where('role', BacktestRunHit::ROLE_ENTRY)
            ->where('as_of_date', $asOfDate)
            ->pluck('stock_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, list<int>> screener_id => stock ids (ExitStrategyEvaluator format)
     */
    public function exitHitsByScreenerForDate(BacktestRun $run, string $asOfDate): array
    {
        $rows = BacktestRunHit::query()
            ->where('backtest_run_id', $run->id)
            ->where('role', BacktestRunHit::ROLE_EXIT)
            ->where('as_of_date', $asOfDate)
            ->get(['screener_id', 'stock_id']);

        $out = [];
        foreach ($rows as $row) {
            $sid = (int) $row->screener_id;
            $out[$sid][] = (int) $row->stock_id;
        }
        foreach ($out as $sid => $ids) {
            $out[$sid] = array_values(array_unique($ids));
        }

        return $out;
    }
}
