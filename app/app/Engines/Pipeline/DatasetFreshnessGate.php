<?php

namespace App\Engines\Pipeline;

use App\Services\DailyMarketSyncService;
use Carbon\Carbon;

/**
 * V4-FEAT-022 — timestamp freshness for the daily decision pipeline.
 *
 * Age is measured from last successful dataset sync to the pipeline run timestamp.
 * Weekdays: 24 hours. Monday (cron timezone): 72 hours. Inclusive (<=).
 * No exchange calendar / holiday logic.
 */
class DatasetFreshnessGate
{
    public const WEEKDAY_MAX_HOURS = 24;

    public const MONDAY_MAX_HOURS = 72;

    public function __construct(
        protected DailyMarketSyncService $dailySync,
    ) {}

    /**
     * @return array{
     *   allowed: bool,
     *   reason: string|null,
     *   message: string,
     *   max_age_hours: int,
     *   age_seconds: int|null,
     *   synced_at: string|null,
     *   pipeline_at: string,
     *   weekday: string
     * }
     */
    public function evaluate(Carbon $pipelineAt): array
    {
        $tz = $this->dailySync->syncTimezone();
        $local = $pipelineAt->copy()->timezone($tz);
        $maxHours = $local->isMonday() ? self::MONDAY_MAX_HOURS : self::WEEKDAY_MAX_HOURS;
        $pipelineIso = $pipelineAt->copy()->timezone($tz)->toIso8601String();
        $syncedAt = $this->dailySync->lastSuccessfulSyncAt();

        $base = [
            'max_age_hours' => $maxHours,
            'pipeline_at' => $pipelineIso,
            'weekday' => $local->englishDayOfWeek,
            'synced_at' => $syncedAt?->toIso8601String(),
            'age_seconds' => null,
        ];

        if ($syncedAt === null) {
            return array_merge($base, [
                'allowed' => false,
                'reason' => 'dataset_not_fresh',
                'message' => 'No successful market dataset sync timestamp. Daily decision pipeline stopped before Discovery.',
            ]);
        }

        $ageSeconds = $pipelineAt->getTimestamp() - $syncedAt->getTimestamp();
        if ($ageSeconds < 0) {
            $ageSeconds = 0;
        }
        $base['age_seconds'] = $ageSeconds;
        $allowed = $ageSeconds <= ($maxHours * 3600);

        if ($allowed) {
            return array_merge($base, [
                'allowed' => true,
                'reason' => null,
                'message' => 'Market dataset is within the allowed freshness window.',
            ]);
        }

        return array_merge($base, [
            'allowed' => false,
            'reason' => 'dataset_not_fresh',
            'message' => sprintf(
                'Market dataset is older than %d hours. Daily decision pipeline stopped before Discovery.',
                $maxHours,
            ),
        ]);
    }
}
