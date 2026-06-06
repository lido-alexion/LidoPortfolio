<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Services\MetricsUpdateService;
use App\Services\StockPriceHistoryService;
use App\Services\SystemLogService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillHistoricalDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $stockId,
        public ?string $fromDate = null,
    ) {}

    public function handle(
        StockPriceHistoryService $history,
        MetricsUpdateService $metricsUpdate,
        SystemLogService $logger,
    ): void {
        $stock = Stock::query()->findOrFail($this->stockId);
        $buyDate = $this->fromDate
            ? Carbon::parse($this->fromDate)
            : Carbon::parse($stock->transactions()->min('transaction_date') ?? now());

        try {
            $sync = $history->ensurePortfolioHistory($stock, $buyDate);

            if (! $sync['success'] && ! $sync['cache_hit']) {
                throw new \RuntimeException($this->formatSyncFailure($stock->symbol, $sync));
            }

            $metricsUpdate->updateStock($stock);
        } catch (\Throwable $e) {
            $logger->log('scheduler', 'Backfill job failed: '.$e->getMessage(), [
                'stock_id' => $stock->id,
            ]);
            throw $e;
        }
    }

    /**
     * @param  array{errors: array<int, string>, from_date?: string, to_date?: string}  $sync
     */
    protected function formatSyncFailure(string $symbol, array $sync): string
    {
        $errors = $sync['errors'] ?? [];
        $summary = $errors === [] ? 'no provider returned data' : implode(' | ', array_slice($errors, 0, 3));

        return "No prices stored for {$symbol}: {$summary}";
    }
}
