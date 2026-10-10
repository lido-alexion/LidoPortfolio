<?php

namespace App\Console\Commands;

use App\Models\Screener;
use App\Services\Screener\ScreenerVersionSnapshotRepairService;
use Illuminate\Console\Command;
use Throwable;

final class RepairScreenerVersionSnapshotCommand extends Command
{
    protected $signature = 'v8:repair-screener-version-snapshot
        {--screener= : Screener id}
        {--version= : Missing historical semantic version}
        {--expected-hash= : Approved semantic SHA-256 hash}
        {--proof-version= : Existing immutable Screener version id in the same lineage}
        {--proof-artifact-version= : Published immutable Screener artifact-version row id}
        {--change-notes= : Audit note recorded on the reconstructed row}
        {--dry-run : Validate all evidence without writing}';

    protected $description = 'Restore one absent historical Screener snapshot only when current semantics match immutable proof';

    public function handle(ScreenerVersionSnapshotRepairService $service): int
    {
        $screener = Screener::query()->find($this->option('screener'));
        if (! $screener) {
            $this->error('Screener was not found.');
            return self::FAILURE;
        }

        $proofVersion = $this->option('proof-version');
        $proofArtifact = $this->option('proof-artifact-version');
        if (($proofVersion === null) === ($proofArtifact === null)) {
            $this->error('Specify exactly one of --proof-version or --proof-artifact-version.');
            return self::FAILURE;
        }

        try {
            $args = [
                $screener,
                (int) $this->option('version'),
                (string) $this->option('expected-hash'),
                (string) ($this->option('change-notes') ?? ''),
            ];
            $result = $proofVersion !== null
                ? $service->restoreFromVersion($args[0], $args[1], (int) $proofVersion, $args[2], $args[3], (bool) $this->option('dry-run'))
                : $service->restoreFromArtifact($args[0], $args[1], (int) $proofArtifact, $args[2], $args[3], (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($result['dry_run']) {
            $this->info('Dry run passed: immutable proof and exact semantic hash match; no row was written.');
        } elseif ($result['created']) {
            $this->info('Reconstructed missing version '.$result['version']->version.' (row '.$result['version']->id.').');
        } else {
            $this->info('Target version already exists with the expected immutable definition; no change made.');
        }

        return self::SUCCESS;
    }
}
