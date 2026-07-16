<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * Keeps the primary index (NIFTY50) current for RS / Explorer.
 * Multi-index batch sync lives in IndexPriceSyncService.
 */
class BenchmarkPriceSyncService
{
    public const KEY_LAST_SYNC_DATE = 'benchmark_price_sync_date';

    public function __construct(
        protected IndexPriceSyncService $indexSync,
        protected IndexCatalogService $catalog,
        protected SettingsService $settings,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * Sync primary benchmark OHLCV. Skips if already synced today unless $force.
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

        $symbol = $this->catalog->primarySymbol();
        $result = $this->indexSync->syncOneSymbol($symbol, 'daily');

        if ($result['success']) {
            Setting::setValue(self::KEY_LAST_SYNC_DATE, $today);
        }

        $payload = [
            'success' => (bool) ($result['success'] ?? false),
            'skipped' => false,
            'stored_rows' => (int) ($result['stored_rows'] ?? 0),
            'fetched_rows' => (int) ($result['fetched_rows'] ?? 0),
            'full_history' => (bool) ($result['full_history'] ?? false),
            'from_date' => (string) ($result['from_date'] ?? $today),
            'to_date' => (string) ($result['to_date'] ?? $today),
            'errors' => $result['errors'] ?? [],
        ];

        $this->logger->scheduler(
            ($payload['success'] ? 'info' : 'warning'),
            $symbol.' benchmark price sync '.($payload['success'] ? 'completed' : 'failed'),
            array_merge(['category' => 'BenchmarkPriceSync', 'symbol' => $symbol], $payload),
        );

        return $payload;
    }

    protected function todayDateString(): string
    {
        $timezone = $this->settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';

        return Carbon::now($timezone)->toDateString();
    }
}
