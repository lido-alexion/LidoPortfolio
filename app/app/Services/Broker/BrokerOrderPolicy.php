<?php

namespace App\Services\Broker;

use App\Services\SettingsService;
use App\Support\TradingCalendar;
use Carbon\Carbon;

final class BrokerOrderPolicy
{
    public const REGULAR = 'regular';
    public const AMO = 'amo';

    public function __construct(protected SettingsService $settings) {}

    public function variety(?Carbon $at = null): string
    {
        $timezone = (string) $this->settings->get('cron_timezone', SettingsService::DEFAULTS['cron_timezone']);
        $time = ($at ?? now())->copy()->timezone($timezone);
        $open = $this->configuredTime('market_open_time', SettingsService::DEFAULTS['market_open_time'], $time);
        $close = $this->configuredTime('market_close_time', SettingsService::DEFAULTS['market_close_time'], $time);

        if (TradingCalendar::isEquitySessionDate($time) && $time->betweenIncluded($open, $close)) {
            return self::REGULAR;
        }

        return self::AMO;
    }

    protected function configuredTime(string $key, string $fallback, Carbon $date): Carbon
    {
        $value = (string) $this->settings->get($key, $fallback);

        try {
            return Carbon::createFromFormat('Y-m-d H:i', $date->toDateString().' '.$value, $date->getTimezone());
        } catch (\Throwable) {
            return Carbon::createFromFormat('Y-m-d H:i', $date->toDateString().' '.$fallback, $date->getTimezone());
        }
    }
}
