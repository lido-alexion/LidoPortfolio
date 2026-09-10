<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Services\Backtest\BacktestSimulationEngine;
use Illuminate\Console\Command;

class ProcessBacktestsCommand extends Command
{
    protected $signature = 'portfolio:process-backtests {--max-runs=1 : Maximum resumable runs in this invocation}';

    protected $description = 'Resume bounded Strategy Backtest slices without requiring an open browser';

    public function handle(BacktestSimulationEngine $engine): int
    {
        $runs = BacktestRun::query()
            ->whereIn('status', [BacktestRun::STATUS_PREPARING, BacktestRun::STATUS_RUNNING])
            ->orderBy('created_at')
            ->limit(max(1, min((int) $this->option('max-runs'), 5)))
            ->get();

        $completed = 0;
        foreach ($runs as $run) {
            $result = $engine->resume($run);
            $completed += ($result['completed'] ?? false) ? 1 : 0;
        }

        $this->info(sprintf('Backtest slices: %d runs; %d completed.', $runs->count(), $completed));

        return self::SUCCESS;
    }
}
