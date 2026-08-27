<?php

namespace Tests\Concerns;

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
        app(DailyMarketSyncService::class)->recordSuccessfulSyncAt($syncedAt);
    }
}
