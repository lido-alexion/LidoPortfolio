<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Automatic decision-pipeline scheduling guard (F148 / F149).
 *
 * Manual runs via `portfolio:decision-pipeline` are never skipped by this service.
 * Scheduled and post-sync triggers share a once-per-day guard and an execution lock
 * in the portfolio timezone.
 */
class DecisionPipelineScheduleService
{
    public const KEY_AUTO_DATE = 'last_decision_pipeline_auto_date';

    public const KEY_AUTO_AT = 'last_decision_pipeline_auto_at';

    public const KEY_AUTO_TRIGGER = 'last_decision_pipeline_auto_trigger';

    public const KEY_AUTO_SUCCESS_DATE = 'decision_pipeline_auto_success_date';

    public const KEY_AUTO_SUCCESS_PROFILES = 'decision_pipeline_auto_success_profiles';

    public const AUTOMATIC_LOCK_KEY = 'trading-os:decision-pipeline:automatic';

    /** Align with scheduler withoutOverlapping(45) on the scheduled event. */
    public const AUTOMATIC_LOCK_SECONDS = 2700;

    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function timezone(): string
    {
        $tz = $this->settings->get('cron_timezone', 'Asia/Kolkata');

        return is_string($tz) && trim($tz) !== '' ? $tz : 'Asia/Kolkata';
    }

    public function todayDateString(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    public function hasAutomaticRunToday(): bool
    {
        return Setting::getValue(self::KEY_AUTO_DATE) === $this->todayDateString();
    }

    public function lastAutomaticTrigger(): ?string
    {
        $trigger = Setting::getValue(self::KEY_AUTO_TRIGGER);

        return is_string($trigger) && $trigger !== '' ? $trigger : null;
    }

    public function shouldSkipAutomatic(string $trigger): bool
    {
        if (! in_array($trigger, ['scheduled', 'post-sync'], true)) {
            return false;
        }

        return $this->hasAutomaticRunToday();
    }

    public function markAutomaticRunToday(string $trigger): void
    {
        Setting::setValue(self::KEY_AUTO_DATE, $this->todayDateString());
        Setting::setValue(self::KEY_AUTO_AT, Carbon::now($this->timezone())->toIso8601String());
        Setting::setValue(self::KEY_AUTO_TRIGGER, $trigger);
    }

    /**
     * @return list<int>
     */
    public function successfulProfileIdsToday(): array
    {
        if (Setting::getValue(self::KEY_AUTO_SUCCESS_DATE) !== $this->todayDateString()) {
            return [];
        }

        $raw = Setting::getValue(self::KEY_AUTO_SUCCESS_PROFILES);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $decoded)));
    }

    public function hasProfileSucceededToday(int $profileId): bool
    {
        return in_array($profileId, $this->successfulProfileIdsToday(), true);
    }

    public function shouldSkipProfileForAutomatic(int $profileId, string $trigger): bool
    {
        if (! in_array($trigger, ['scheduled', 'post-sync'], true)) {
            return false;
        }

        return $this->hasProfileSucceededToday($profileId);
    }

    public function markProfileSuccessfulToday(int $profileId): void
    {
        $today = $this->todayDateString();
        $storedDate = Setting::getValue(self::KEY_AUTO_SUCCESS_DATE);

        if ($storedDate !== $today) {
            Setting::setValue(self::KEY_AUTO_SUCCESS_DATE, $today);
            Setting::setValue(self::KEY_AUTO_SUCCESS_PROFILES, json_encode([$profileId]));

            return;
        }

        $ids = $this->successfulProfileIdsToday();
        if (! in_array($profileId, $ids, true)) {
            $ids[] = $profileId;
            sort($ids);
            Setting::setValue(self::KEY_AUTO_SUCCESS_PROFILES, json_encode(array_values($ids)));
        }
    }

    /**
     * @param  list<int>  $profileIds
     */
    public function allProfilesSucceededToday(array $profileIds): bool
    {
        if ($profileIds === []) {
            return true;
        }

        $succeeded = $this->successfulProfileIdsToday();
        foreach ($profileIds as $profileId) {
            if (! in_array((int) $profileId, $succeeded, true)) {
                return false;
            }
        }

        return true;
    }

    public function acquireAutomaticLock(): ?Lock
    {
        $lock = Cache::lock(self::AUTOMATIC_LOCK_KEY, self::AUTOMATIC_LOCK_SECONDS);
        if (! $lock->get()) {
            return null;
        }

        return $lock;
    }
}
