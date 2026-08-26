<?php

namespace Tests\Concerns;

use App\Models\Setting;
use App\Services\DailyMarketSyncService;
use Carbon\Carbon;

trait MarksDailyDatasetPublished
{
    protected function markDailyDatasetPublished(): void
    {
        $this->markLastSuccessfulDatasetSyncAt(now());
    }

    protected function markLastSuccessfulDatasetSyncAt(Carbon $syncedAt): void
    {
        $sync = app(DailyMarketSyncService::class);
        Setting::setValue(
            DailyMarketSyncService::KEY_SYNC_DATE,
            $syncedAt->copy()->timezone($sync->syncTimezone())->toDateString(),
        );
        Setting::setValue(DailyMarketSyncService::KEY_SYNC_SUCCESS, '1');
        Setting::setValue(DailyMarketSyncService::KEY_SYNCED_AT, $syncedAt->toIso8601String());
    }
}
