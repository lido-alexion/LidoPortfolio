<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlAcceptanceSource;
use App\Services\ML\NseAcceptanceSourceBootstrapService;
use Illuminate\Console\Command;

class DownloadNseAcceptanceSourcesCommand extends Command
{
    protected $signature = 'ml:download-nse-acceptance-sources
        {--campaign-id= : Acceptance campaign whose exact reference dates must be staged}
        {--actor-id= : Existing StoX Admin user ID recorded as the source actor}
        {--validation-timeout=180 : Maximum seconds to wait for each queued source validation}
        {--poll-ms=500 : Source validation polling interval in milliseconds}';

    protected $description = 'Download official NSE bhavcopies for an acceptance campaign and stage them as immutable sources';

    public function handle(NseAcceptanceSourceBootstrapService $bootstrap): int
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
            $this->error('The campaign has no persisted reference dates. Refresh its preflight first.');

            return self::FAILURE;
        }

        $timeout = max(10, (int) $this->option('validation-timeout'));
        $pollMicroseconds = max(100, (int) $this->option('poll-ms')) * 1000;
        $sealed = $skipped = 0;
        $failures = [];
        $total = count($dates);
        foreach ($dates as $index => $date) {
            $existing = $bootstrap->latestForDate($date);
            if ($existing?->status === 'sealed') {
                $skipped++;
                $this->line(sprintf('[%d/%d] %s already sealed', $index + 1, $total, $date));
                continue;
            }
            try {
                if ($existing?->status === 'queued') {
                    $source = $existing;
                } else {
                    $source = $bootstrap->stage($date, (int) $actorId, $existing?->status === 'uploading' ? $existing : null);
                }
                $source = $this->waitForValidation($source, $timeout, $pollMicroseconds);
                if ($source->status !== 'sealed') {
                    throw new \RuntimeException('Source validation ended with status '.$source->status.'.');
                }
                $sealed++;
                $this->info(sprintf('[%d/%d] %s sealed', $index + 1, $total, $date));
            } catch (\Throwable $exception) {
                $failures[$date] = $exception->getMessage();
                $this->error(sprintf('[%d/%d] %s failed: %s', $index + 1, $total, $date, $exception->getMessage()));
                if (str_contains($exception->getMessage(), 'Timed out waiting for the dedicated acceptance worker')) {
                    break;
                }
            }
        }

        $summary = ['campaign_id' => $campaign->id, 'requested' => $total, 'sealed_now' => $sealed,
            'already_sealed' => $skipped, 'failed' => count($failures), 'failed_dates' => array_keys($failures)];
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function waitForValidation(MlAcceptanceSource $source, int $timeout, int $pollMicroseconds): MlAcceptanceSource
    {
        $deadline = microtime(true) + $timeout;
        while (in_array($source->status, ['uploading', 'queued'], true) && microtime(true) < $deadline) {
            usleep($pollMicroseconds);
            $source->refresh();
        }
        if (in_array($source->status, ['uploading', 'queued'], true)) {
            throw new \RuntimeException('Timed out waiting for the dedicated acceptance worker.');
        }

        return $source;
    }
}
