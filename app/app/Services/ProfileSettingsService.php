<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;

class ProfileSettingsService
{
    public const DEFAULTS = [
        'default_stoploss_percent' => '10',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'notifications_enabled' => 'true',
        'notification_schedules' => '[]',
    ];

    public function get(PortfolioProfile $profile, string $key, ?string $default = null): ?string
    {
        return ProfileSetting::getValue(
            $profile->id,
            $key,
            $default ?? (self::DEFAULTS[$key] ?? null),
        );
    }

    public function set(PortfolioProfile $profile, string $key, ?string $value): void
    {
        ProfileSetting::setValue($profile->id, $key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(PortfolioProfile $profile): array
    {
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'notification_schedules') {
                continue;
            }
            $settings[$key] = $this->get($profile, $key, $default);
        }

        $settings['notification_schedules'] = app(NotificationScheduleService::class)
            ->schedulesForProfile($profile);

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function update(PortfolioProfile $profile, array $data): array
    {
        if (array_key_exists('notification_schedules', $data)) {
            $times = is_array($data['notification_schedules']) ? $data['notification_schedules'] : [];
            app(NotificationScheduleService::class)->persistForProfile($profile, $times);
            unset($data['notification_schedules']);
        }

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || $key === 'notification_schedules') {
                continue;
            }
            $this->set($profile, $key, $value === null ? null : (string) $value);
        }

        return $this->all($profile);
    }

    public function isManagedKey(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }
}
