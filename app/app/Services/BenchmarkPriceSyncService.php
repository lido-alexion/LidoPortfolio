<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

class BenchmarkPriceSyncService
{
    public const KEY_LAST_SYNC_DATE = 'benchmark_price_sync_date';

    public function __construct(
        protected PriceFetchService $priceFetch,
        protected RelativeStrengthService $relativeStrength,
        protected StockPriceHistoryService $history,
        protected SettingsService $settings,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * Sync NIFTY50 OHLCV for Explorer / relative strength. Skips if already synced today unless $force.
     *
     * @return array{
     *   success: bool,
     *   skipped: bool,
     *   stored_rows: int,
     *   fetched_rows: int,
     *   full_history: bool,
     *   from_date: string,
     *   to_date: string,
     *   errors: array<int, string>
     * }
     */
    public function syncIfNeeded(bool $force = false): array
    {
        $today = $this->todayDateString();
        $lastSyncDate = Setting::getValue(self::KEY_LAST_SYNC_DATE);

        if (! $force && $lastSyncDate === $today) {
            return [
                'success' => true,
                'skipped' => true,
                'stored_rows' => 0,
                'fetched_rows' => 0,
                'full_history' => false,
                'from_date' => $today,
                'to_date' => $today,
                'errors' => [],
            ];
        }

        $benchmark = $this->relativeStrength->benchmarkStock();
        $needsFullHistory = ! $this->history->getCachedAnalyticsHistoryStatus($benchmark, 6)['cache_hit'];
        $from = $needsFullHistory
            ? now()->subMonths(12)->startOfDay()
            : now()->subDays($this->dailyLookbackDays())->startOfDay();
        $to = now()->startOfDay();

        $result = $this->priceFetch->syncStock($benchmark, $from, $to, notifyTelegramOnFailure: false);

        if ($result['success']) {
            Setting::setValue(self::KEY_LAST_SYNC_DATE, $today);
        }

        $payload = [
            'success' => (bool) ($result['success'] ?? false),
            'skipped' => false,
            'stored_rows' => (int) ($result['stored_rows'] ?? 0),
            'fetched_rows' => (int) ($result['fetched_rows'] ?? 0),
            'full_history' => $needsFullHistory,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'errors' => $result['errors'] ?? [],
        ];

        $this->logger->scheduler(
            ($payload['success'] ? 'info' : 'warning'),
            'NIFTY50 benchmark price sync '.($payload['success'] ? 'completed' : 'failed'),
            array_merge(['category' => 'BenchmarkPriceSync'], $payload),
        );

        return $payload;
    }

    protected function todayDateString(): string
    {
        $timezone = $this->settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';

        return Carbon::now($timezone)->toDateString();
    }

    protected function dailyLookbackDays(): int
    {
        return max(14, (int) config('portfolio.universe_price_sync.daily_lookback_days', 10));
    }
}
