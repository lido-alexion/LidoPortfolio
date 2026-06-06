<?php

namespace App\Services;

use App\Models\PortfolioSnapshot;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PortfolioSnapshotRebuildService
{
    public function __construct(
        protected PortfolioHistoricalHoldingsService $historicalHoldings,
        protected StockPriceHistoryService $priceHistory,
        protected StockQuoteService $quotes,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{
     *   from_date: string,
     *   to_date: string,
     *   snapshots_written: int,
     *   trading_days: int,
     *   missing_price_warnings: int,
     *   prices_fetched: int,
     *   duration_ms: int
     * }
     */
    public function rebuildFromDate(User $user, Carbon $fromDate): array
    {
        $from = $fromDate->copy()->startOfDay();
        $to = now()->startOfDay();

        if ($from->gt($to)) {
            $from = $to->copy();
        }

        return $this->rebuildDateRange($user, $from, $to);
    }

    /**
     * @return array{
     *   from_date: string,
     *   to_date: string,
     *   snapshots_written: int,
     *   trading_days: int,
     *   missing_price_warnings: int,
     *   prices_fetched: int,
     *   duration_ms: int
     * }
     */
    public function rebuildDateRange(User $user, Carbon $fromDate, Carbon $toDate): array
    {
        $started = microtime(true);
        $from = $fromDate->copy()->startOfDay();
        $to = $toDate->copy()->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $this->logger->api('info', 'Portfolio snapshot rebuild started', [
            'category' => 'SnapshotRebuild',
            'user_id' => $user->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
        ]);

        $transactions = $this->loadUserTransactions($user);
        $stockIds = $transactions->pluck('stock_id')->unique()->map(fn ($id) => (int) $id)->values()->all();

        $pricesFetched = $this->ensureHistoricalPrices($user, $transactions, $from, $to, $stockIds);

        $tradingDates = $this->resolveTradingDates($stockIds, $from, $to);
        $priceIndex = $this->buildPriceIndex($stockIds, $from->copy()->subMonths(3), $to);

        $snapshotsWritten = 0;
        $missingPriceWarnings = 0;

        foreach ($tradingDates as $date) {
            $state = $this->calculatePortfolioStateForDate($user, $date, $transactions, $priceIndex);
            $missingPriceWarnings += (int) ($state['missing_price_count'] ?? 0);
            unset($state['missing_price_count']);
            $this->persistSnapshot($user, $date, $state);
            $snapshotsWritten++;
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);

        $result = [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'snapshots_written' => $snapshotsWritten,
            'trading_days' => count($tradingDates),
            'missing_price_warnings' => $missingPriceWarnings,
            'prices_fetched' => $pricesFetched,
            'duration_ms' => $durationMs,
        ];

        $this->logger->api('info', 'Portfolio snapshot rebuild completed', [
            'category' => 'SnapshotRebuild',
            'user_id' => $user->id,
            ...$result,
        ]);

        return $result;
    }

    /**
     * @return array{
     *   portfolio_value: float,
     *   invested_value: float,
     *   unrealized_profit: float,
     *   holdings: array<int, array{stock_id: int, quantity: float, invested_amount: float, close: float|null, market_value: float}>
     * }
     */
    public function calculatePortfolioStateForDate(
        User $user,
        Carbon $date,
        ?Collection $transactions = null,
        ?array $priceIndex = null,
    ): array {
        $date = $date->copy()->startOfDay();
        $transactions ??= $this->loadUserTransactions($user);
        $holdings = $this->historicalHoldings->holdingsAsOf($transactions, $date);

        $portfolioValue = 0.0;
        $investedValue = 0.0;
        $detail = [];
        $missingPriceCount = 0;

        foreach ($holdings as $stockId => $holding) {
            $qty = (float) $holding['quantity'];
            $invested = (float) $holding['invested_amount'];
            $investedValue += $invested;

            $close = $priceIndex !== null
                ? $this->closeFromIndex($priceIndex, $stockId, $date)
                : $this->quotes->latestClose($stockId, $date);

            if ($close === null || $close <= 0) {
                $missingPriceCount++;
                $this->logger->api('warning', 'Missing historical close for snapshot date', [
                    'category' => 'SnapshotRebuild',
                    'user_id' => $user->id,
                    'stock_id' => $stockId,
                    'snapshot_date' => $date->toDateString(),
                ]);
                $close = 0.0;
            }

            $marketValue = $qty * $close;
            $portfolioValue += $marketValue;

            $detail[$stockId] = [
                'stock_id' => $stockId,
                'quantity' => $qty,
                'invested_amount' => round($invested, 4),
                'close' => $close > 0 ? round($close, 4) : null,
                'market_value' => round($marketValue, 4),
            ];
        }

        return [
            'portfolio_value' => round($portfolioValue, 4),
            'invested_value' => round($investedValue, 4),
            'unrealized_profit' => round($portfolioValue - $investedValue, 4),
            'holdings' => $detail,
            'missing_price_count' => $missingPriceCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildAfterTransactionChange(
        User $user,
        ?string $previousTransactionDate = null,
        ?string $newTransactionDate = null,
    ): array {
        $dates = array_filter([$previousTransactionDate, $newTransactionDate]);

        if ($dates === []) {
            return $this->rebuildFromDate($user, now()->startOfDay());
        }

        $from = Carbon::parse(min($dates))->startOfDay();

        return $this->rebuildFromDate($user, $from);
    }

    protected function loadUserTransactions(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int>  $stockIds
     */
    protected function ensureHistoricalPrices(
        User $user,
        Collection $transactions,
        Carbon $from,
        Carbon $to,
        array $stockIds,
    ): int {
        $fetched = 0;

        foreach ($stockIds as $stockId) {
            $stock = Stock::query()->find($stockId);
            if (! $stock) {
                continue;
            }

            $firstTxDate = $transactions
                ->where('stock_id', $stockId)
                ->min(fn ($tx) => $tx->transaction_date);

            if (! $firstTxDate) {
                continue;
            }

            $requiredFrom = Carbon::parse($firstTxDate)->startOfDay();
            if ($requiredFrom->gt($from)) {
                $requiredFrom = $from->copy();
            }

            $result = $this->priceHistory->fetchMissingHistory($stock, $requiredFrom, $to);
            $fetched += (int) ($result['stored_rows'] ?? 0);

            if (! ($result['success'] ?? false) && ($result['errors'] ?? []) !== []) {
                $this->logger->provider('warning', 'OHLCV gap fill incomplete during snapshot rebuild', [
                    'category' => 'SnapshotRebuild',
                    'user_id' => $user->id,
                    'symbol' => $stock->symbol,
                    'from' => $requiredFrom->toDateString(),
                    'to' => $to->toDateString(),
                    'errors' => array_slice($result['errors'], 0, 3),
                ]);
            }
        }

        return $fetched;
    }

    /**
     * Trading-day dates with price rows for held symbols, plus today.
     *
     * @param  array<int>  $stockIds
     * @return array<int, Carbon>
     */
    protected function resolveTradingDates(array $stockIds, Carbon $from, Carbon $to): array
    {
        if ($stockIds === []) {
            return [$to->copy()];
        }

        $dates = StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->whereBetween('price_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->orderBy('price_date')
            ->pluck('price_date')
            ->map(fn ($d) => Carbon::parse($d)->startOfDay());

        $unique = [];
        foreach ($dates as $date) {
            $unique[$date->toDateString()] = $date;
        }

        $unique[$to->toDateString()] = $to->copy();

        ksort($unique);

        return array_values($unique);
    }

    /**
     * @param  array<int>  $stockIds
     * @return array<int, array<int, array{date: string, close: float}>>
     */
    protected function buildPriceIndex(array $stockIds, Carbon $from, Carbon $to): array
    {
        if ($stockIds === []) {
            return [];
        }

        $rows = StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->where('price_date', '>=', $from->toDateString())
            ->where('price_date', '<=', $to->toDateString())
            ->orderBy('price_date')
            ->get(['stock_id', 'price_date', 'close_price', 'adjusted_close_price']);

        $index = [];
        foreach ($rows as $row) {
            $close = $row->adjusted_close_price ?? $row->close_price;
            if ($close === null) {
                continue;
            }
            $dateKey = $row->price_date instanceof Carbon
                ? $row->price_date->toDateString()
                : Carbon::parse($row->price_date)->toDateString();

            $index[(int) $row->stock_id][] = [
                'date' => $dateKey,
                'close' => (float) $close,
            ];
        }

        return $index;
    }

    /**
     * @param  array<int, array<int, array{date: string, close: float}>>  $priceIndex
     */
    protected function closeFromIndex(array $priceIndex, int $stockId, Carbon $date): ?float
    {
        $series = $priceIndex[$stockId] ?? [];
        if ($series === []) {
            return null;
        }

        $target = $date->toDateString();
        $last = null;

        foreach ($series as $point) {
            if ($point['date'] <= $target) {
                $last = $point['close'];
            } else {
                break;
            }
        }

        return $last;
    }

    /**
     * @param  array{portfolio_value: float, invested_value: float}  $state
     */
    protected function persistSnapshot(User $user, Carbon $date, array $state): void
    {
        PortfolioSnapshot::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'snapshot_date' => $date->toDateString(),
            ],
            [
                'portfolio_value' => $state['portfolio_value'],
                'invested_value' => $state['invested_value'],
                'created_at' => now(),
            ],
        );
    }
}
