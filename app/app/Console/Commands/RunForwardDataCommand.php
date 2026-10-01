<?php

namespace App\Console\Commands;

use App\Models\ForwardCollectionWork;
use App\Services\ForwardDataPlanner;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Services\ML\NseHistoricalUniverseArchiveProvider;
use App\Exceptions\MlHistoricalUniverseProviderException;
use Illuminate\Console\Command;

class RunForwardDataCommand extends Command
{
    protected $signature = 'stox:forward-data {--batch= : Maximum owner work items to claim}';
    protected $description = 'Plan and recover bounded forward-data obligations through existing owner engines';

    public function handle(ForwardDataPlanner $planner, NseHistoricalUniverseArchiveProvider $provider, MlHistoricalUniverseMembershipService $memberships): int
    {
        $plan = $planner->plan();
        if (($plan['status'] ?? null) === 'blocked_configuration') {
            $this->warn(json_encode($plan, JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
        $claimed = $planner->claim((int) ($this->option('batch') ?: config('forward_data.batch', 20)));
        $completed = 0;
        foreach ($claimed as $work) {
            try {
                $snapshot = $provider->snapshotForDate($work->session_date->toDateString());
                $memberships->backfillHistoricalSnapshots([$snapshot], 'forward_official_nse', null, MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE);
                if ($planner->succeed($work, (string) $work->lease_token, $snapshot['diagnostics'] ?? [])) $completed++;
            } catch (MlHistoricalUniverseProviderException $error) {
                $retryable = $error->retryable;
                $exhausted = $work->attempts >= (int) config('forward_data.max_attempts', 8);
                $planner->fail($work, (string) $work->lease_token, $exhausted ? 'exhausted' : ($retryable ? ForwardDataPlanner::STATE_WAITING_PUBLICATION : 'blocked_quality'), $retryable ? 'missing_publication' : 'quality_rejection', $error->getMessage(), $exhausted ? null : now()->addMinutes(min(360, 15 * (2 ** max(0, $work->attempts - 1)))));
            } catch (\Throwable $error) {
                $planner->fail($work, (string) $work->lease_token, 'retry_wait', 'persistence_failure', $error->getMessage(), now()->addMinutes(30));
            }
        }
        $this->info(json_encode(['plan' => $plan, 'claimed' => count($claimed), 'completed' => $completed], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
