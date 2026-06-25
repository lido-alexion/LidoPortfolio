<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSetting;

class UserSettingsService
{
    public const DEFAULTS = [
        'default_stoploss_percent' => '10',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'notifications_enabled' => 'true',
        'notification_schedules' => '[]',
    ];

    public function get(User $user, string $key, ?string $default = null): ?string
    {
        return UserSetting::getValue(
            $user->id,
            $key,
            $default ?? (self::DEFAULTS[$key] ?? null),
        );
    }

    public function set(User $user, string $key, ?string $value): void
    {
        UserSetting::setValue($user->id, $key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(User $user): array
    {
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'notification_schedules') {
                continue;
            }
            $settings[$key] = $this->get($user, $key, $default);
        }

        $settings['notification_schedules'] = app(NotificationScheduleService::class)
            ->schedulesForUser($user);

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function update(User $user, array $data): array
    {
        if (array_key_exists('notification_schedules', $data)) {
            $times = is_array($data['notification_schedules']) ? $data['notification_schedules'] : [];
            app(NotificationScheduleService::class)->persistForUser($user, $times);
            unset($data['notification_schedules']);
        }

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || $key === 'notification_schedules') {
                continue;
            }
            $this->set($user, $key, $value === null ? null : (string) $value);
        }

        return $this->all($user);
    }

    public function isManagedKey(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }
}
