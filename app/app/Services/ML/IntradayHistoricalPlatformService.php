<?php

namespace App\Services\ML;

use App\Models\V8\IntradayBackfillCheckpoint;
use Illuminate\Support\Facades\File;

/**
 * FEAT-065 foundation — corpus location, checkpoint inventory, operator status (Mac-hosted backfill is out-of-band).
 */
class IntradayHistoricalPlatformService
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $root = (string) config('intraday_ml_platform.corpus_root');
        $exists = is_dir($root);
        $parquetFiles = 0;
        if ($exists) {
            $files = File::allFiles($root);
            foreach ($files as $file) {
                if (str_ends_with(strtolower($file->getFilename()), '.parquet')) {
                    $parquetFiles++;
                }
            }
        }

        $counts = IntradayBackfillCheckpoint::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return [
            'enabled' => (bool) config('intraday_ml_platform.enabled', false),
            'universe' => config('intraday_ml_platform.universe'),
            'bar_interval' => config('intraday_ml_platform.bar_interval'),
            'storage_format' => config('intraday_ml_platform.storage_format'),
            'target_years' => (int) config('intraday_ml_platform.target_years', 8),
            'corpus_root' => $root,
            'corpus_root_exists' => $exists,
            'parquet_file_count' => $parquetFiles,
            'checkpoint_counts' => $counts,
        ];
    }
}
