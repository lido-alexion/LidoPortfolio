<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Services\ML\MlAcceptanceBackfillService;
use App\Services\ML\NseAcceptanceSourceBootstrapService;
use Illuminate\Console\Command;

class RunNseAcceptanceBackfillCommand extends Command
{
    protected $signature = 'ml:acceptance-backfill-nse-sources
        {--campaign-id= : Acceptance campaign whose exact reference dates must be covered}
        {--actor-id= : Existing StoX Admin user ID recorded as the operator}
        {--apply-run-id= : Explicitly apply a previously completed preview run}
        {--wait-seconds=14400 : Maximum seconds to wait for preview or apply completion}
        {--poll-ms=1000 : Backfill polling interval in milliseconds}';

    protected $description = 'Preview or explicitly apply sealed NSE acceptance sources through the governed backfill service';

    public function handle(NseAcceptanceSourceBootstrapService $bootstrap, MlAcceptanceBackfillService $backfill): int
    {
        $campaign = MlAcceptanceCampaign::query()->find((string) $this->option('campaign-id'));
        if ($campaign === null) {
            $this->error('Acceptance campaign not found.');

            return self::FAILURE;
        }
        $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null || ! $actor->is_admin) {
            $this->error('Provide an existing StoX Admin actor ID.');

            return self::FAILURE;
        }
        $dates = $bootstrap->referenceDates($campaign);
        if ($dates === []) {
            $this->error('The campaign has no persisted reference dates.');

            return self::FAILURE;
        }

        $applyRunId = $this->option('apply-run-id');
        if ($applyRunId !== null) {
            $run = MlUniverseSnapshotBackfillRun::query()->find((int) $applyRunId);
            if ($run === null || $this->sorted($run->requested_dates ?? []) !== $dates) {
                $this->error('The preview run does not match this campaign reference-date set.');

                return self::FAILURE;
            }
            $backfill->action($run, 'apply', (int) $actorId);
            $this->info('Apply queued for preview run '.$run->id.'.');
        } else {
            $sources = [];
            $missing = [];
            foreach ($dates as $date) {
                $source = MlAcceptanceSource::query()
                    ->where('manifest->source', 'nse_cash_bhavcopy')
                    ->where('manifest->date', $date)
                    ->where('status', 'sealed')
                    ->latest('created_at')
                    ->first();
                if ($source === null) {
                    $missing[] = $date;
                } else {
                    $sources[] = $source->id;
                }
            }
            if ($missing !== []) {
                $this->error(count($missing).' campaign dates do not have sealed sources.');
                $this->line(json_encode(['missing_dates' => $missing], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $run = $backfill->preview($sources, (int) $actorId);
            $this->info('Preview queued as run '.$run->id.'.');
        }

        $run = $this->waitForRun($run, max(60, (int) $this->option('wait-seconds')), max(100, (int) $this->option('poll-ms')) * 1000);
        $this->line(json_encode(['run_id' => $run->id, 'status' => $run->status,
            'processed' => count($run->processed_dates ?? []), 'failed_dates' => $run->failed_dates ?? []], JSON_THROW_ON_ERROR));

        $complete = $backfill->isComplete($run);
        if ($run->status === 'completed' && ! $complete) {
            $this->error('Backfill marked completed without complete requested-date evidence.');
        }

        return $complete ? self::SUCCESS : self::FAILURE;
    }

    private function waitForRun(MlUniverseSnapshotBackfillRun $run, int $timeout, int $pollMicroseconds): MlUniverseSnapshotBackfillRun
    {
        $deadline = microtime(true) + $timeout;
        while (in_array($run->status, ['queued', 'running'], true) && microtime(true) < $deadline) {
            usleep($pollMicroseconds);
            $run->refresh();
        }
        if (in_array($run->status, ['queued', 'running'], true)) {
            throw new \RuntimeException('Timed out waiting for the dedicated acceptance worker.');
        }

        return $run;
    }

    /** @param list<string> $dates
     * @return list<string>
     */
    private function sorted(array $dates): array
    {
        $dates = array_values(array_unique(array_map('strval', $dates)));
        sort($dates);

        return $dates;
    }
}
