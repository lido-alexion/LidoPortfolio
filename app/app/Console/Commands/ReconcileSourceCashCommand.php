<?php

namespace App\Console\Commands;

use App\Services\SourceCashReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileSourceCashCommand extends Command
{
    protected $signature = 'cash:reconcile-source {source : Path to a normalized private evidence JSON file} {--output= : Optional report path}';

    protected $description = 'Reconcile source cash evidence without reading or writing the application database';

    public function handle(SourceCashReconciliationService $reconciler): int
    {
        try {
            $sourcePath = (string) $this->argument('source');
            if (! is_file($sourcePath) || ! is_readable($sourcePath)) throw new \RuntimeException('Source file is not readable.');
            $source = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($source)) throw new \RuntimeException('Source JSON must decode to an object.');
            $report = $reconciler->reconcile($source);
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $outputPath = $this->option('output');
            if ($outputPath) {
                if (file_put_contents((string) $outputPath, $encoded, LOCK_EX) === false) throw new \RuntimeException('Could not write report output.');
                $this->info('Read-only cash report written to '.$outputPath);
            } else {
                $this->line(rtrim($encoded));
            }
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
