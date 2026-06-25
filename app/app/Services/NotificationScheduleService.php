<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSetting;

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
     * @return array<int, string> Unique HH:mm times (24-hour) for the user.
     */
    public function schedulesForUser(User $user): array
    {
        $raw = UserSetting::getValue(
            $user->id,
            'notification_schedules',
            UserSettingsService::DEFAULTS['notification_schedules'],
        );
        $decoded = json_decode($raw ?? '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalize($decoded);
    }

    /**
     * Union of all users' notification times (for Laravel scheduler registration).
     *
     * @return array<int, string>
     */
    public function distinctSchedulesAcrossUsers(): array
    {
        $times = [];

        foreach (User::query()->orderBy('id')->pluck('id') as $userId) {
            $user = User::query()->find($userId);
            if (! $user) {
                continue;
            }
            foreach ($this->schedulesForUser($user) as $time) {
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
    public function persistForUser(User $user, array $times): array
    {
        $normalized = $this->normalize($times);
        UserSetting::setValue($user->id, 'notification_schedules', json_encode($normalized));

        return $normalized;
    }
}
