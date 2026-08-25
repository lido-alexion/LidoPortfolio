<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;

class ProfileSettingsService
{
    public const DEFAULTS = [
        'default_stoploss_percent' => '10',
        /** OD-22: portfolio trailing % seed / default (independent of stop-loss). */
        'portfolio_trailing_percent' => '15',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'notifications_enabled' => 'true',
        'notification_schedules' => '[]',
        'indiavix_alert_enabled' => 'true',
        'indiavix_alert_threshold' => '20',
        'portfolio_cash_reserve_pct' => '',
        /** OD-12 §12.4 — portfolio override; blank = platform default ₹5,000. */
        'minimum_actionable_buy_amount' => '',
    ];

    /** Internal arming flag — not exposed via settings API defaults list. */
    public const INDIAVIX_ALERT_ARMED_KEY = 'indiavix_alert_armed';

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

        $rearmVix = array_key_exists('indiavix_alert_enabled', $data)
            || array_key_exists('indiavix_alert_threshold', $data);

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || $key === 'notification_schedules') {
                continue;
            }
            $this->set($profile, $key, $value === null ? null : (string) $value);
        }

        if ($rearmVix) {
            // Re-arm so a new threshold / re-enable can fire on the next evaluation.
            $this->set($profile, self::INDIAVIX_ALERT_ARMED_KEY, 'true');
        }

        return $this->all($profile);
    }

    public function isIndiaVixAlertArmed(PortfolioProfile $profile): bool
    {
        return $this->get($profile, self::INDIAVIX_ALERT_ARMED_KEY, 'true') !== 'false';
    }

    public function setIndiaVixAlertArmed(PortfolioProfile $profile, bool $armed): void
    {
        $this->set($profile, self::INDIAVIX_ALERT_ARMED_KEY, $armed ? 'true' : 'false');
    }

    public function isManagedKey(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }
}
