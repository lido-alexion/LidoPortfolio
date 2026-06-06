<?php

namespace App\Services;

use App\Jobs\DailyMarketDataJob;
use App\Models\Setting;
use Carbon\Carbon;
use Throwable;

class DailyMarketSyncService
{
    public const KEY_SYNC_DATE = 'last_daily_market_sync_date';

    public const KEY_SYNC_SUCCESS = 'last_daily_market_sync_success';

    public const KEY_SYNCED_AT = 'last_daily_market_sync_at';

    public const KEY_SYNC_IN_PROGRESS = 'last_daily_market_sync_in_progress';

    public const KEY_SYNC_STARTED_AT = 'last_daily_market_sync_started_at';

    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function syncTimezone(): string
    {
        return $this->settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
    }

    public function todayDateString(): string
    {
        return Carbon::now($this->syncTimezone())->toDateString();
    }

    public function isSyncInProgress(): bool
    {
        if (Setting::getValue(self::KEY_SYNC_IN_PROGRESS, '0') !== '1') {
            return false;
        }

        $startedAt = Setting::getValue(self::KEY_SYNC_STARTED_AT);
        if ($startedAt && Carbon::parse($startedAt)->lt(now()->subMinutes(20))) {
            $this->clearInProgress();

            return false;
        }

        return true;
    }

    public function markInProgress(): void
    {
        Setting::setValue(self::KEY_SYNC_IN_PROGRESS, '1');
        Setting::setValue(self::KEY_SYNC_STARTED_AT, now()->toIso8601String());
    }

    public function clearInProgress(): void
    {
        Setting::setValue(self::KEY_SYNC_IN_PROGRESS, '0');
        Setting::setValue(self::KEY_SYNC_STARTED_AT, null);
    }

    public function hasSyncedSuccessfullyToday(): bool
    {
        $today = $this->todayDateString();
        $date = Setting::getValue(self::KEY_SYNC_DATE);
        $success = Setting::getValue(self::KEY_SYNC_SUCCESS, '0');

        return $date === $today && $success === '1';
    }

    /**
     * @return array{
     *   synced_today: bool,
     *   sync_date: string|null,
     *   synced_at: string|null,
     *   today: string,
     *   timezone: string,
     *   in_progress: bool
     * }
     */
    public function status(): array
    {
        return [
            'synced_today' => $this->hasSyncedSuccessfullyToday(),
            'sync_date' => Setting::getValue(self::KEY_SYNC_DATE),
            'synced_at' => Setting::getValue(self::KEY_SYNCED_AT),
            'today' => $this->todayDateString(),
            'timezone' => $this->syncTimezone(),
            'in_progress' => $this->isSyncInProgress(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runDailySyncIfNeeded(bool $force = false): array
    {
        if (! $force && $this->hasSyncedSuccessfullyToday()) {
            return array_merge([
                'skipped' => true,
                'success' => true,
                'message' => 'Daily price sync already completed successfully for today.',
            ], $this->status());
        }

        if ($this->isSyncInProgress()) {
            return array_merge([
                'skipped' => true,
                'success' => false,
                'message' => 'Daily price sync is already running.',
            ], $this->status());
        }

        $this->markInProgress();

        try {
            @set_time_limit(0);
            DailyMarketDataJob::dispatchSync();

            return array_merge([
                'skipped' => false,
                'success' => $this->hasSyncedSuccessfullyToday(),
                'message' => $this->hasSyncedSuccessfullyToday()
                    ? 'Daily price sync completed.'
                    : 'Daily price sync finished with errors. You can try again.',
            ], $this->status());
        } catch (Throwable $e) {
            $this->clearInProgress();
            report($e);

            return array_merge([
                'skipped' => false,
                'success' => false,
                'message' => 'Daily price sync failed: '.$e->getMessage(),
            ], $this->status());
        }
    }

    public function markSuccessful(): void
    {
        Setting::setValue(self::KEY_SYNC_DATE, $this->todayDateString());
        Setting::setValue(self::KEY_SYNC_SUCCESS, '1');
        Setting::setValue(self::KEY_SYNCED_AT, Carbon::now($this->syncTimezone())->toIso8601String());
    }

    public function markIncomplete(int $processed, int $failed): void
    {
        Setting::setValue(self::KEY_SYNC_DATE, $this->todayDateString());
        Setting::setValue(self::KEY_SYNC_SUCCESS, '0');
        Setting::setValue(
            self::KEY_SYNCED_AT,
            Carbon::now($this->syncTimezone())->toIso8601String().";processed={$processed};failed={$failed}",
        );
    }
}
