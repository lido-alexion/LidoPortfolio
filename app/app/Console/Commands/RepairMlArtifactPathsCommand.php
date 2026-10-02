<?php

namespace App\Console\Commands;

use App\Models\V7\MlModelVersion;
use App\Services\ML\MlArtifactRepairService;
use Illuminate\Console\Command;
use Throwable;

class RepairMlArtifactPathsCommand extends Command
{
    protected $signature = 'portfolio:ml-artifacts-repair {--dry-run : Verify and report without filesystem or database writes}';

    protected $description = 'Verify and canonicalize ML artifact paths without promoting models';

    public function handle(MlArtifactRepairService $repair): int
    {
        $failed = false;
        foreach (MlModelVersion::query()->select('id')->lazyById() as $model) {
            try {
                $result = $repair->repair($model->id, (bool) $this->option('dry-run'));
                $this->line($model->id.' '.$result['status'].' '.($result['path'] ?? '-'));
            } catch (Throwable $exception) {
                $failed = true;
                $this->error($model->id.' failed: '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
