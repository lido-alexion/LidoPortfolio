<?php

namespace App\Console\Commands;

use App\Models\PortfolioReplayRun;
use App\Services\Simulation\PortfolioReplayProcessor;
use Illuminate\Console\Command;

class ProcessPortfolioReplaysCommand extends Command
{
    protected $signature = 'portfolio:process-replays {--max-runs=10} {--max-sessions=5}';
    protected $description = 'Process bounded, resumable Portfolio Replay slices after priority Paper work';

    public function handle(PortfolioReplayProcessor $processor): int
    {
        $runs = PortfolioReplayRun::query()->whereIn('status', ['queued', 'running'])
            ->orderByRaw("case when status = 'running' then 0 else 1 end")
            ->orderBy('created_at')->limit(max(1, min((int) $this->option('max-runs'), 50)))->get();
        $sessions = 0;
        foreach ($runs as $run) {
            $sessions += (int) ($processor->process($run, (int) $this->option('max-sessions'))['processed_sessions'] ?? 0);
        }
        $this->info(sprintf('Replay slices: %d runs; %d session checkpoints.', $runs->count(), $sessions));
        return self::SUCCESS;
    }
}
