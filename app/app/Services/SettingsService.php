<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\SyncLogService;

class SettingsService
{
    public const DEFAULTS = [
        'cron_time' => '18:30',
        'cron_timezone' => 'Asia/Kolkata',
        'nse_retry_count' => '3',
        'alpha_vantage_api_key' => '',
        'backend_log_level' => 'info',
        'sync_log_retention_days' => '7',
        'fee_components' => '',
    ];

    public function __construct(
        protected ProfileSettingsService $profileSettings,
    ) {}

    /**
     * Global + active profile settings merged for the Settings UI.
     * Non-admins receive only per-profile keys plus read-only {@see cron_timezone} for notification labels.
     *
     * @return array<string, mixed>
     */
    public function allForProfile(PortfolioProfile $profile, User $user): array
    {
        $settings = $this->profileSettings->all($profile);

        if (! $user->is_admin) {
            $settings['cron_timezone'] = $this->get(
                'cron_timezone',
                self::DEFAULTS['cron_timezone'],
            );

            return $settings;
        }

        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'fee_components') {
                continue;
            }
            $settings[$key] = $this->get($key, $default);
        }

        $settings['fee_components'] = app(FeeCalculatorService::class)->componentsFromSettings();

        $syncLogService = app(SyncLogService::class);
        $settings['sync_log_latest_runs'] = [
            'daily_market_data' => $syncLogService->latestRunSummary(SyncLogService::JOB_DAILY_MARKET_DATA),
            'stock_master' => $syncLogService->latestRunSummary(SyncLogService::JOB_STOCK_MASTER),
        ];

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateForProfile(PortfolioProfile $profile, User $user, array $data): array
    {
        $profileData = [];
        $globalData = [];

        foreach ($data as $key => $value) {
            if ($this->profileSettings->isManagedKey($key)) {
                $profileData[$key] = $value;
            } elseif (array_key_exists($key, self::DEFAULTS) || $key === 'fee_components') {
                $globalData[$key] = $value;
            }
        }

        if ($globalData !== [] && ! $user->is_admin) {
            abort(403, 'Admin access required to change application settings.');
        }

        if ($globalData !== []) {
            $this->updateGlobal($globalData);
        }

        if ($profileData !== []) {
            $this->profileSettings->update($profile, $profileData);
        }

        return $this->allForProfile($profile, $user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateGlobal(array $data): array
    {
        if (array_key_exists('fee_components', $data)) {
            $components = is_array($data['fee_components']) ? $data['fee_components'] : [];
            $normalized = app(FeeCalculatorService::class)->normalizeComponents($components);
            \App\Models\Setting::setValue('fee_components', json_encode($normalized));
            unset($data['fee_components']);
        }

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || $key === 'fee_components') {
                continue;
            }
            \App\Models\Setting::setValue($key, $value === null ? null : (string) $value);
        }

        return $this->globalOnly();
    }

    /**
     * @return array<string, string|null>
     */
    public function globalOnly(): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'fee_components') {
                continue;
            }
            $settings[$key] = $this->get($key, $default);
        }

        return $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return \App\Models\Setting::getValue($key, $default ?? (self::DEFAULTS[$key] ?? null));
    }
}
