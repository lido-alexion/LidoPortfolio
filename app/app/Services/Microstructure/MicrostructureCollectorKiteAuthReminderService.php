<?php

namespace App\Services\Microstructure;

use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Notification\NotificationPublisher;
use App\Support\TradingCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * V8 FEAT-063 §11 — Telegram reminders when the collector Kite session is missing on trading days.
 */
class MicrostructureCollectorKiteAuthReminderService
{
    public const CONDITION_KEY = 'microstructure-collector:kite-auth';

    public function __construct(
        protected BrokerConnectionService $connections,
        protected NotificationPublisher $publisher,
    ) {}

    /**
     * @return array{skipped:bool,sent:bool,reason:?string}
     */
    public function sendDue(?Carbon $now = null): array
    {
        if (! config('microstructure_collector.enabled')) {
            return ['skipped' => true, 'sent' => false, 'reason' => 'disabled'];
        }

        $timezone = (string) config('microstructure_collector.market_timezone', 'Asia/Kolkata');
        $instant = ($now ?? now())->copy()->timezone($timezone);

        if (! TradingCalendar::isScheduledMarketDataDay($instant)) {
            return ['skipped' => true, 'sent' => false, 'reason' => 'not_trading_day'];
        }

        $userId = config('microstructure_collector.kite_user_id');
        $user = is_int($userId) ? User::query()->find($userId) : null;
        if ($user === null) {
            return ['skipped' => true, 'sent' => false, 'reason' => 'no_kite_user'];
        }

        if ($this->connections->status($user)['usable'] ?? false) {
            $this->publisher->resolveCondition(self::CONDITION_KEY);
            Cache::forget($this->cacheKey($instant));

            return ['skipped' => true, 'sent' => false, 'reason' => 'kite_ready'];
        }

        $premarket = (string) config('microstructure_collector.kite_auth_reminder_time', '08:45');
        $time = $instant->format('H:i');
        $sessionStart = (string) config('microstructure_collector.market_session_start', '09:15');
        $sessionEnd = (string) config('microstructure_collector.market_session_end', '15:30');

        $inReminderWindow = $time >= $premarket && $time <= $sessionEnd;
        if (! $inReminderWindow) {
            return ['skipped' => true, 'sent' => false, 'reason' => 'outside_window'];
        }

        $hourBucket = $instant->format('Y-m-d-H');
        $isPremarketSlot = $time === $premarket;
        $isHourlySlot = $time >= $sessionStart && $instant->minute === 0;
        if (! $isPremarketSlot && ! $isHourlySlot) {
            return ['skipped' => true, 'sent' => false, 'reason' => 'not_due'];
        }

        if (Cache::get($this->cacheKey($instant)) === $hourBucket) {
            return ['skipped' => true, 'sent' => false, 'reason' => 'already_sent'];
        }

        $route = (string) config('microstructure_collector.kite_auth_reminder_route', '/dashboard');
        $this->publisher->publishCondition(self::CONDITION_KEY, [$user], [
            'notification_type' => 'microstructure.kite_auth',
            'audience' => 'investor',
            'severity' => 'action_required',
            'title' => 'Microstructure collector needs Kite login',
            'message' => 'Live tick collection requires today’s Zerodha session. Open StoX and connect Kite (same link every trading day).',
            'context' => ['collector' => true],
            'primary_action' => ['label' => 'Connect Kite', 'route' => $route],
        ]);

        Cache::put($this->cacheKey($instant), $hourBucket, $instant->copy()->endOfDay());

        return ['skipped' => false, 'sent' => true, 'reason' => null];
    }

    protected function cacheKey(Carbon $instant): string
    {
        return 'microstructure_kite_auth_reminder:'.$instant->toDateString();
    }
}
