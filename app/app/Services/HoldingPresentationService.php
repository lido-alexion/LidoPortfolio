<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Services\Risk\OwnershipEpisodeService;
use App\Services\Risk\PortfolioStopLossCalculator;
use App\Services\Risk\PortfolioTrailingStopCalculator;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class HoldingPresentationService
{
    public function __construct(
        protected SettingsService $settings,
        protected ProfileSettingsService $profileSettings,
        protected PriceFetchService $priceFetch,
        protected StockQuoteService $quotes,
        protected XirrService $xirr,
        protected OwnershipEpisodeService $ownershipEpisodes,
        protected PortfolioStopLossCalculator $stopLossCalculator,
        protected PortfolioTrailingStopCalculator $trailingStopCalculator,
        protected StockPriceHistoryService $priceHistory,
    ) {}

    public function firstBuyDateForCurrentPosition(PortfolioProfile $profile, Stock $stock): ?Carbon
    {
        $transactions = Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $quantity = 0.0;
        $firstBuyDate = null;

        foreach ($transactions as $transaction) {
            $qty = (float) $transaction->quantity;

            if ($transaction->type === 'buy') {
                if ($quantity <= 0.00001) {
                    $firstBuyDate = Carbon::parse($transaction->transaction_date);
                }
                $quantity += $qty;
            } else {
                $quantity -= $qty;
                if ($quantity <= 0.00001) {
                    $quantity = 0;
                    $firstBuyDate = null;
                }
            }
        }

        return $quantity > 0.00001 ? $firstBuyDate : null;
    }

    public function enrichHolding(PortfolioProfile $profile, Holding $holding): array
    {
        $stock = $holding->stock;
        $firstBuyDate = $stock
            ? $this->ownershipEpisodes->firstBuyDateForHolding($profile, $holding, $stock)
            : null;
        $metric = $stock?->metrics;

        $highestCloseSinceBuy = null;
        $highestCloseSinceBuyDate = null;
        $latestClose = null;
        $priceRowCount = 0;
        $latestPriceDate = null;

        if ($firstBuyDate && $stock) {
            $priceQuery = StockPrice::query()
                ->where('stock_id', $stock->id)
                ->where('price_date', '>=', $firstBuyDate->toDateString());

            $priceRowCount = (clone $priceQuery)->count();
            // OD-14: peak uses raw close_price only (never adjusted_close / high / low).
            $highestCloseSinceBuy = $this->ownershipEpisodes->peakRawCloseSinceEntry($stock, $firstBuyDate);
            if ($highestCloseSinceBuy !== null) {
                $highestCloseSinceBuyDate = (clone $priceQuery)
                    ->where('close_price', $highestCloseSinceBuy)
                    ->orderByDesc('price_date')
                    ->value('price_date');
            }
            $latestPriceDate = (clone $priceQuery)->max('price_date');
            $latestClose = $this->ownershipEpisodes->latestRawCloseSinceEntry($stock, $firstBuyDate);
        }

        if ($latestClose === null && $metric?->latest_close !== null) {
            $latestClose = (float) $metric->latest_close;
        }

        $stoplossPercent = (float) $this->profileSettings->get($profile, 'default_stoploss_percent', '10');
        $trailingPercent = (float) $this->profileSettings->get($profile, 'portfolio_trailing_percent', '15');

        // V3 portfolio trailing (§15 / OD-22) — independent of SL % and of V1 unrealized-% proxy.
        $trailingStop = $this->trailingStopCalculator->trailingStopPrice(
            $highestCloseSinceBuy !== null ? (float) $highestCloseSinceBuy : null,
            $trailingPercent,
        );
        if ($trailingStop !== null) {
            $trailingStop = round($trailingStop, 4);
        }

        $stopLossPrice = null;
        $weightedAverageFillCost = null;
        if ($stock && $firstBuyDate) {
            $fills = $this->ownershipEpisodes->fillsForCurrentEpisode($profile, $holding, $stock);
            if ($fills !== []) {
                $avgCost = $this->stopLossCalculator->weightedAverageFillCost($fills);
                $weightedAverageFillCost = round($avgCost, 4);
                $stopLossPrice = round(
                    $this->stopLossCalculator->stopPrice($avgCost, $stoplossPercent),
                    4,
                );
            }
        }

        // XIRR terminal must use the same latest close as the holdings table (since buy, then metrics).
        $terminalClose = ($latestClose !== null && (float) $latestClose > 0)
            ? (float) $latestClose
            : $this->quotes->latestClose((int) $stock->id);
        $marketValue = (float) $holding->quantity * $terminalClose;
        $investedAmount = (float) $holding->invested_amount;
        $unrealizedProfit = $terminalClose > 0
            ? round($marketValue - $investedAmount, 4)
            : null;
        $unrealizedGainPercent = ($unrealizedProfit !== null && $investedAmount > 0)
            ? round(($unrealizedProfit / $investedAmount) * 100, 2)
            : null;

        $dailyChangePercent = null;
        $previousPriceDate = null;

        if ($stock) {
            $recentPrices = StockPrice::query()
                ->where('stock_id', $stock->id)
                ->orderByDesc('price_date')
                ->limit(2)
                ->get(['price_date', 'close_price']);

            if ($recentPrices->count() >= 2) {
                $previousClose = (float) $recentPrices[1]->close_price;
                if ($previousClose > 0) {
                    $dailyChangePercent = round(
                        (((float) $recentPrices[0]->close_price - $previousClose) / $previousClose) * 100,
                        2,
                    );
                }
                $previousPriceDate = Carbon::parse($recentPrices[1]->price_date)->toDateString();
            }
        }

        $payload = $holding->toArray();
        $payload['strategy_id'] = $holding->strategy_id !== null ? (int) $holding->strategy_id : null;
        $payload['owner_key'] = $holding->owner_key ?: Holding::OWNER_UNMANAGED;
        $payload['is_unmanaged'] = $holding->isUnmanaged();
        $targetAmount = $holding->target_amount !== null ? round((float) $holding->target_amount, 4) : null;
        $filledAmount = $holding->filled_amount !== null
            ? round((float) $holding->filled_amount, 4)
            : (($holding->quantity !== null && (float) $holding->quantity > 0.00001)
                ? round((float) $holding->invested_amount, 4)
                : 0.0);
        $payload['target_amount'] = $targetAmount;
        $payload['filled_amount'] = $filledAmount;
        // OD-12 remaining = max(0, target − filled); null target → no remaining semantics.
        $payload['remaining_target_amount'] = $targetAmount !== null
            ? round(max(0.0, $targetAmount - max(0.0, (float) $filledAmount)), 4)
            : null;
        $payload['unrealized_profit'] = $unrealizedProfit;
        $payload['unrealized_gain_percent'] = $unrealizedGainPercent;
        $payload['xirr'] = $this->xirr->calculateStockXirr(
            $profile,
            (int) $stock->id,
            null,
            $marketValue,
        );
        $payload['stoploss_summary'] = [
            'first_buy_date' => $firstBuyDate?->toDateString(),
            'highest_close_since_buy' => $highestCloseSinceBuy !== null ? round((float) $highestCloseSinceBuy, 4) : null,
            'highest_close_since_buy_date' => $highestCloseSinceBuyDate
                ? Carbon::parse($highestCloseSinceBuyDate)->toDateString()
                : null,
            'trailing_stop_price' => $trailingStop,
            'stop_loss_price' => $stopLossPrice,
            'weighted_average_fill_cost' => $weightedAverageFillCost,
            'stoploss_percent' => $stoplossPercent,
            'portfolio_trailing_percent' => $trailingPercent,
            'latest_close' => $latestClose !== null ? round((float) $latestClose, 4) : null,
            'price_row_count' => $priceRowCount,
            'latest_price_date' => $latestPriceDate,
            'previous_price_date' => $previousPriceDate,
            'daily_change_percent' => $dailyChangePercent,
            'has_price_history' => $priceRowCount > 0,
        ];

        return $payload;
    }

    public function priceHistoryForHolding(PortfolioProfile $profile, Stock $stock, string $range = 'all'): array
    {
        $firstBuyDate = $this->firstBuyDateForCurrentPosition($profile, $stock);

        if (! $firstBuyDate) {
            abort(404, 'No active holding found for this stock.');
        }

        $allPrices = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderBy('price_date')
            ->get();

        $sinceBuyPrices = $allPrices
            ->filter(fn (StockPrice $row) => Carbon::parse($row->price_date)->toDateString() >= $firstBuyDate->toDateString())
            ->values();

        $range = strtolower($range);
        if (! in_array($range, ['all', 'since_buy'], true)) {
            $range = 'all';
        }

        $prices = ($range === 'since_buy' ? $sinceBuyPrices : $allPrices)
            ->sortByDesc('price_date')
            ->values();

        return [
            'stock' => $stock->only(['id', 'symbol', 'name', 'exchange']),
            'range' => $range,
            'from_date' => $prices->last()?->price_date?->toDateString(),
            'to_date' => $prices->first()?->price_date?->toDateString(),
            'all_from_date' => $allPrices->first()?->price_date?->toDateString(),
            'all_to_date' => $allPrices->last()?->price_date?->toDateString(),
            'since_buy_from_date' => $firstBuyDate->toDateString(),
            'price_count' => $prices->count(),
            'all_price_count' => $allPrices->count(),
            'since_buy_price_count' => $sinceBuyPrices->count(),
            'has_price_history' => $prices->isNotEmpty(),
            'data' => $prices,
        ];
    }

    public function syncHistoricalPrices(PortfolioProfile $profile, Stock $stock): array
    {
        $firstBuyDate = $this->firstBuyDateForCurrentPosition($profile, $stock);

        if (! $firstBuyDate) {
            abort(404, 'No active holding found for this stock.');
        }

        $syncResult = $this->priceHistory->fetchAllAvailableHistory($stock);

        if (! ($syncResult['success'] ?? false)) {
            $failedSync = [
                'errors' => (array) ($syncResult['errors'] ?? []),
                'from_date' => $this->priceHistory->allAvailableHistoryFrom()->toDateString(),
                'to_date' => now()->toDateString(),
                'fetched_rows' => (int) ($syncResult['fetched_rows'] ?? 0),
            ];
            throw ValidationException::withMessages([
                'sync' => [$this->formatSyncFailureMessage($stock->symbol, $failedSync)],
            ]);
        }

        app(MetricsUpdateService::class)->updateStock($stock);

        $history = $this->priceHistoryForHolding($profile, $stock, 'all');

        return [
            'message' => "Stored {$syncResult['stored_rows']} price rows for {$stock->symbol}",
            'sync' => [
                'stored_rows' => (int) ($syncResult['stored_rows'] ?? 0),
                'fetched_rows' => (int) ($syncResult['fetched_rows'] ?? 0),
                'from_date' => $this->priceHistory->allAvailableHistoryFrom()->toDateString(),
                'to_date' => now()->toDateString(),
                'provider' => (($syncResult['ranges_fetched'][0]['provider'] ?? null) ?? (($syncResult['cache_hit'] ?? false) ? 'cache' : 'none')),
                'success' => (bool) ($syncResult['success'] ?? false),
                'errors' => (array) ($syncResult['errors'] ?? []),
                'cache_hit' => (bool) ($syncResult['cache_hit'] ?? false),
            ],
            'stored_rows' => (int) ($syncResult['stored_rows'] ?? 0),
            'rows_since_buy_date' => $history['since_buy_price_count'] ?? 0,
            ...$history,
        ];
    }

    /**
     * @param  array{errors: array<int, string>, from_date: string, to_date: string, fetched_rows?: int}  $sync
     */
    protected function formatSyncFailureMessage(string $symbol, array $sync): string
    {
        $errors = $sync['errors'] ?? [];

        if ($errors !== []) {
            $hints = [];
            foreach ($errors as $error) {
                if (str_contains($error, 'cURL error 60') || str_contains($error, 'certificate')) {
                    $hints[] = 'SSL certificate issue: set CURL_CAFILE in .env to a valid cacert.pem (see DEPLOYMENT_VALIDATION_PLAN.md).';
                    break;
                }
            }
            foreach ($errors as $error) {
                if (str_contains($error, 'Alpha Vantage API key not configured')) {
                    $hints[] = 'Add alpha_vantage_api_key in Settings, or fix NSE/Yahoo connectivity.';
                    break;
                }
            }

            $detail = implode(' | ', array_slice($errors, 0, 2));

            return "Could not fetch prices for {$symbol} ({$sync['from_date']} to {$sync['to_date']}). {$detail}"
                .($hints ? ' '.implode(' ', $hints) : '');
        }

        return "Could not fetch prices for {$symbol} ({$sync['from_date']} to {$sync['to_date']}). All providers returned no data.";
    }
}
