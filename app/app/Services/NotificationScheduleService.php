<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;
use App\Models\User;

class NotificationScheduleService
{
    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function timezone(): string
    {
        return $this->settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
    }

    /**
     * @return array<int, string> Unique HH:mm times (24-hour) for the profile.
     */
    public function schedulesForProfile(PortfolioProfile $profile): array
    {
        $raw = ProfileSetting::getValue(
            $profile->id,
            'notification_schedules',
            ProfileSettingsService::DEFAULTS['notification_schedules'],
        );
        $decoded = json_decode($raw ?? '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalize($decoded);
    }

    /**
     * Union of all profiles' notification times (for Laravel scheduler registration).
     *
     * @return array<int, string>
     */
    public function distinctSchedulesAcrossProfiles(): array
    {
        $times = [];

        foreach (PortfolioProfile::query()->orderBy('id')->pluck('id') as $profileId) {
            $profile = PortfolioProfile::query()->find($profileId);
            if (! $profile) {
                continue;
            }
            foreach ($this->schedulesForProfile($profile) as $time) {
                $times[$time] = $time;
            }
        }

        $list = array_values($times);
        sort($list);

        return $list;
    }

    /**
     * @param  array<int, string>  $times
     * @return array<int, string>
     */
    public function normalize(array $times): array
    {
        $valid = [];
        foreach ($times as $time) {
            if (! is_string($time)) {
                continue;
            }
            $time = trim($time);
            if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $time, $matches) !== 1) {
                continue;
            }
            $hour = (int) $matches[1];
            if ($hour < 0 || $hour > 23) {
                continue;
            }
            $normalized = sprintf('%02d:%s', $hour, $matches[2]);
            $valid[$normalized] = $normalized;
        }

        $list = array_values($valid);
        sort($list);

        return $list;
    }

    /**
     * @param  array<int, string>  $times
     */
    public function persistForProfile(PortfolioProfile $profile, array $times): array
    {
        $normalized = $this->normalize($times);
        ProfileSetting::setValue($profile->id, 'notification_schedules', json_encode($normalized));

        return $normalized;
    }
}
