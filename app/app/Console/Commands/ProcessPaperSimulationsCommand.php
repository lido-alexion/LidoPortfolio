<?php

namespace App\Console\Commands;

use App\Models\PortfolioProfile;
use App\Services\Simulation\PaperSimulationProcessor;
use App\Support\TradingCalendar;
use Illuminate\Console\Command;

class ProcessPaperSimulationsCommand extends Command
{
    protected $signature = 'portfolio:process-paper-simulations
        {--through= : Last eligible session date, defaults to the latest required price session}
        {--max-portfolios=20 : Maximum Paper portfolios in this invocation}
        {--max-sessions=5 : Maximum sessions per Portfolio in this invocation}';

    protected $description = 'Process a bounded chronological, resumable slice of Paper Portfolio simulation';

    public function handle(PaperSimulationProcessor $processor): int
    {
        $through = (string) ($this->option('through') ?: TradingCalendar::lastRequiredPriceSession()->toDateString());
        $limit = max(1, min((int) $this->option('max-portfolios'), 100));
        $sessions = max(1, min((int) $this->option('max-sessions'), 31));
        $totals = ['processed' => 0, 'fills' => 0, 'waiting' => 0, 'behind' => 0];

        PortfolioProfile::query()->where('portfolio_type', PortfolioProfile::TYPE_PAPER)
            ->where('simulation_state', '!=', PortfolioProfile::SIMULATION_PAUSED)
            ->orderByRaw("case when simulation_state = 'waiting' then 0 else 1 end")
            ->orderBy('simulation_checkpoint_date')->orderBy('id')->limit($limit)->get()
            ->each(function (PortfolioProfile $profile) use ($processor, $through, $sessions, &$totals): void {
                $result = $processor->process($profile, $through, $sessions);
                $totals['processed']++;
                $totals['fills'] += (int) ($result['fills'] ?? 0);
                $totals['waiting'] += ($result['status'] ?? null) === 'waiting' ? 1 : 0;
                $totals['behind'] += ($result['status'] ?? null) === 'behind' ? 1 : 0;
            });

        $this->info(sprintf(
            'Paper simulations: %d portfolios; %d fills; %d waiting; %d behind; through %s.',
            $totals['processed'], $totals['fills'], $totals['waiting'], $totals['behind'], $through,
        ));

        return self::SUCCESS;
    }
}
