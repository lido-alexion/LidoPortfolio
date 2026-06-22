<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public const DEFAULTS = [
        'cron_time' => '18:30',
        'cron_timezone' => 'Asia/Kolkata',
        'nse_retry_count' => '3',
        'default_stoploss_percent' => '10',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'alpha_vantage_api_key' => '',
        'notifications_enabled' => 'true',
        'backend_log_level' => 'info',
        'sync_log_retention_days' => '7',
        'fee_components' => '',
    ];

    public function all(): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'fee_components') {
                continue;
            }
            $settings[$key] = Setting::getValue($key, $default);
        }

        $settings['notification_schedules'] = app(NotificationScheduleService::class)->schedules();
        $settings['fee_components'] = app(FeeCalculatorService::class)->componentsFromSettings();

        $syncLogService = app(SyncLogService::class);
        $settings['sync_log_latest_runs'] = [
            'daily_market_data' => $syncLogService->latestRunSummary(SyncLogService::JOB_DAILY_MARKET_DATA),
            'stock_master' => $syncLogService->latestRunSummary(SyncLogService::JOB_STOCK_MASTER),
        ];

        return $settings;
    }

    public function update(array $data): array
    {
        if (array_key_exists('notification_schedules', $data)) {
            $times = is_array($data['notification_schedules']) ? $data['notification_schedules'] : [];
            app(NotificationScheduleService::class)->persist($times);
            unset($data['notification_schedules']);
        }

        if (array_key_exists('fee_components', $data)) {
            $components = is_array($data['fee_components']) ? $data['fee_components'] : [];
            $normalized = app(FeeCalculatorService::class)->normalizeComponents($components);
            Setting::setValue('fee_components', json_encode($normalized));
            unset($data['fee_components']);
        }

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || $key === 'fee_components') {
                continue;
            }
            Setting::setValue($key, $value === null ? null : (string) $value);
        }

        return $this->all();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return Setting::getValue($key, $default ?? (self::DEFAULTS[$key] ?? null));
    }
}
