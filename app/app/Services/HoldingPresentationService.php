<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class HoldingPresentationService
{
    public function __construct(
        protected SettingsService $settings,
        protected UserSettingsService $userSettings,
        protected PriceFetchService $priceFetch,
        protected StockQuoteService $quotes,
        protected XirrService $xirr,
    ) {}

    public function firstBuyDateForCurrentPosition(User $user, Stock $stock): ?Carbon
    {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
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

    public function enrichHolding(User $user, Holding $holding): array
    {
        $stock = $holding->stock;
        $firstBuyDate = $this->firstBuyDateForCurrentPosition($user, $stock);
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
            $highestCloseSinceBuy = (clone $priceQuery)->max('close_price');
            if ($highestCloseSinceBuy !== null) {
                $highestCloseSinceBuyDate = (clone $priceQuery)
                    ->where('close_price', $highestCloseSinceBuy)
                    ->orderByDesc('price_date')
                    ->value('price_date');
            }
            $latestPriceDate = (clone $priceQuery)->max('price_date');
            $latestClose = $this->quotes->latestCloseSince($stock->id, $firstBuyDate);
        }

        if ($latestClose === null && $metric?->latest_close !== null) {
            $latestClose = (float) $metric->latest_close;
        }

        $stoplossPercent = (float) $this->userSettings->get($user, 'default_stoploss_percent', '10');
        $trailingStop = null;

        if ($highestCloseSinceBuy !== null && (float) $highestCloseSinceBuy > 0) {
            $trailingStop = round((float) $highestCloseSinceBuy * (1 - ($stoplossPercent / 100)), 4);
        }

        // XIRR terminal must use the same latest close as the holdings table (since buy, then metrics).
        $terminalClose = ($latestClose !== null && (float) $latestClose > 0)
            ? (float) $latestClose
            : $this->quotes->latestClose((int) $stock->id);
        $marketValue = (float) $holding->quantity * $terminalClose;

        $payload = $holding->toArray();
        $payload['xirr'] = $this->xirr->calculateStockXirr(
            $user,
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
            'stoploss_percent' => $stoplossPercent,
            'latest_close' => $latestClose !== null ? round((float) $latestClose, 4) : null,
            'price_row_count' => $priceRowCount,
            'latest_price_date' => $latestPriceDate,
            'has_price_history' => $priceRowCount > 0,
        ];

        return $payload;
    }

    public function priceHistoryForHolding(User $user, Stock $stock): array
    {
        $firstBuyDate = $this->firstBuyDateForCurrentPosition($user, $stock);

        if (! $firstBuyDate) {
            abort(404, 'No active holding found for this stock.');
        }

        $prices = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $firstBuyDate->toDateString())
            ->orderByDesc('price_date')
            ->get();

        return [
            'stock' => $stock->only(['id', 'symbol', 'name', 'exchange']),
            'from_date' => $firstBuyDate->toDateString(),
            'price_count' => $prices->count(),
            'has_price_history' => $prices->isNotEmpty(),
            'data' => $prices,
        ];
    }

    public function syncHistoricalPrices(User $user, Stock $stock): array
    {
        $firstBuyDate = $this->firstBuyDateForCurrentPosition($user, $stock);

        if (! $firstBuyDate) {
            abort(404, 'No active holding found for this stock.');
        }

        $sync = $this->priceFetch->syncStock($stock, $firstBuyDate, now());

        if (! $sync['success']) {
            throw ValidationException::withMessages([
                'sync' => [$this->formatSyncFailureMessage($stock->symbol, $sync)],
            ]);
        }

        app(MetricsUpdateService::class)->updateStock($stock);

        $history = $this->priceHistoryForHolding($user, $stock);

        return [
            'message' => "Stored {$sync['stored_rows']} price rows for {$stock->symbol} via {$sync['provider']}",
            'sync' => $sync,
            'stored_rows' => $sync['stored_rows'],
            'rows_since_buy_date' => $history['price_count'],
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
