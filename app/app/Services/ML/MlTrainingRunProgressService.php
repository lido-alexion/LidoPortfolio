<?php

namespace App\Services\ML;

use App\Models\V7\MlTrainingRun;

/**
 * FEAT-056 — durable training progress (authoritative; SSE is presentation only).
 */
class MlTrainingRunProgressService
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(MlTrainingRun $run, string $stage, int $percent, array $extra = []): void
    {
        $configuration = $run->configuration ?? [];
        $configuration['progress'] = array_merge($configuration['progress'] ?? [], [
            'stage' => $stage,
            'percent' => max(0, min(100, $percent)),
            'updated_at' => now()->toIso8601String(),
        ], $extra);

        $run->forceFill(['configuration' => $configuration])->save();
    }
}
