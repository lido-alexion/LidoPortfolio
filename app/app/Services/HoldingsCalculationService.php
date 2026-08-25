<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class HoldingsCalculationService
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
    ) {}

    public function recalculateForProfile(PortfolioProfile $profile): Collection
    {
        $stockIds = Transaction::query()
            ->where('profile_id', $profile->id)
            ->distinct()
            ->pluck('stock_id');

        $holdings = collect();

        foreach ($stockIds as $stockId) {
            $holdings = $holdings->merge(
                $this->recalculateOwnerLotsForProfileStock($profile, Stock::query()->findOrFail($stockId))
            );
        }

        Holding::query()
            ->where('profile_id', $profile->id)
            ->whereNotIn('stock_id', $stockIds)
            // OD-12: keep pre-fill strategy targets until first fill or explicit clear.
            ->where(function ($q) {
                $q->whereNull('target_amount')
                    ->orWhere('target_amount', '<=', 0);
            })
            ->delete();

        return $holdings;
    }

    /**
     * Recalculate holdings for one stock. Returns one representative Holding for
     * callers that expect a single row (corporate actions, tests). When ownership
     * can be fully attributed, multiple owner lots may exist — use
     * {@see recalculateOwnerLotsForProfileStock()} to obtain all of them.
     */
    public function recalculateForProfileStock(PortfolioProfile $profile, Stock $stock): Holding
    {
        $lots = $this->recalculateOwnerLotsForProfileStock($profile, $stock);

        return $lots->first() ?? Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => null,
            'owner_key' => Holding::OWNER_UNMANAGED,
            'quantity' => 0,
            'avg_buy_price' => 0,
            'invested_amount' => 0,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
    }

    /**
     * Upsert owner-scoped lots when every ledger row can be attributed; otherwise
     * fall back to a single blended row (WS1 residual limitation).
     *
     * @return Collection<int, Holding>
     */
    public function recalculateOwnerLotsForProfileStock(PortfolioProfile $profile, Stock $stock): Collection
    {
        $transactions = $this->transactionsForProfileStock($profile, $stock);
        $partition = $this->partitionTransactionsByOwner($transactions);

        if ($partition['attributable']) {
            return $this->persistAttributedLots($profile, $stock, $partition['lots'], $transactions);
        }

        return collect([$this->persistBlendedLot($profile, $stock, $transactions)]);
    }

    /**
     * Dry-run replay after hypothetically removing a transaction.
     *
     * @throws InvalidArgumentException when remaining ledger is invalid (e.g. orphan sells)
     */
    public function assertReplayValidAfterDeleting(PortfolioProfile $profile, Transaction $toDelete): void
    {
        $remaining = $this->transactionsForProfileStock($profile, Stock::query()->findOrFail($toDelete->stock_id))
            ->reject(fn (Transaction $tx) => (int) $tx->id === (int) $toDelete->id)
            ->values();

        $this->replayTransactions($remaining, $profile, $toDelete->stock, dryRun: true);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{
     *   quantity: float,
     *   avg_buy_price: float,
     *   invested_amount: float,
     *   total_fees: float,
     *   realized_profit: float
     * }
     *
     * @throws InvalidArgumentException
     */
    public function replayTransactions(
        Collection $transactions,
        ?PortfolioProfile $profile = null,
        ?Stock $stock = null,
        bool $dryRun = false,
    ): array {
        $quantity = 0.0;
        $avgBuyPrice = 0.0;
        $investedAmount = 0.0;
        $totalFees = 0.0;
        $realizedProfit = 0.0;
        $wasZero = true;

        foreach ($transactions as $transaction) {
            $qty = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = (float) $transaction->fees;

            if ($transaction->type === 'buy') {
                if (! $dryRun && $wasZero && $quantity <= 0 && $profile !== null && $stock !== null) {
                    $this->resetMetricsForNewEntry($profile, $stock);
                    $wasZero = false;
                    $totalFees = 0.0;
                } elseif ($wasZero && $quantity <= 0) {
                    $wasZero = false;
                    $totalFees = 0.0;
                }

                $investedAmount += $qty * $price;
                $totalFees += $fees;
                $quantity += $qty;
                $avgBuyPrice = $quantity > 0 ? $investedAmount / $quantity : 0;
            } else {
                if ($qty > $quantity + 0.00001) {
                    throw new InvalidArgumentException('Cannot sell more quantity than currently owned.');
                }

                $realizedProfit += (($price - $avgBuyPrice) * $qty) - $fees;
                $totalFees += $fees;
                $quantity -= $qty;
                $investedAmount = $avgBuyPrice * $quantity;

                if ($quantity <= 0.00001) {
                    $quantity = 0;
                    $avgBuyPrice = 0;
                    $investedAmount = 0;
                    $totalFees = 0;
                    $wasZero = true;

                    if (! $dryRun && $profile !== null && $stock !== null) {
                        $this->deactivateTracking($stock);
                        app(AlertExpirationService::class)->expireForProfileStockIfUnheld($profile, $stock);
                    }
                }
            }
        }

        return [
            'quantity' => $quantity,
            'avg_buy_price' => $avgBuyPrice,
            'invested_amount' => $investedAmount,
            'total_fees' => $totalFees,
            'realized_profit' => $realizedProfit,
        ];
    }

    public function getAvailableQuantity(PortfolioProfile $profile, Stock $stock): float
    {
        return (float) Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->sum('quantity');
    }

    /**
     * Open quantity after replaying all transactions on or before the given date (inclusive).
     */
    public function quantityAsOfDate(PortfolioProfile $profile, Stock $stock, string $asOfDate): float
    {
        $transactions = $this->transactionsForProfileStock($profile, $stock)
            ->filter(fn (Transaction $tx) => $tx->transaction_date->format('Y-m-d') <= $asOfDate)
            ->values();

        $state = $this->replayTransactions($transactions, dryRun: true);

        return $state['quantity'];
    }

    /**
     * OD-10: open quantity as of date, partitioned by parent owner when attributable.
     *
     * When the ledger cannot be fully attributed, returns a single blended bucket under
     * {@see Holding::OWNER_UNMANAGED} (safe fallback — do not invent owners).
     *
     * @return array{
     *   attributable: bool,
     *   quantities: array<string, float>,
     *   lots: array<string, Collection<int, Transaction>>
     * }
     */
    public function quantityAsOfDateByOwner(PortfolioProfile $profile, Stock $stock, string $asOfDate): array
    {
        $transactions = $this->transactionsForProfileStock($profile, $stock)
            ->filter(fn (Transaction $tx) => $tx->transaction_date->format('Y-m-d') <= $asOfDate)
            ->values();

        $partition = $this->partitionTransactionsByOwner($transactions);

        if (! $partition['attributable']) {
            $state = $this->replayTransactions($transactions, dryRun: true);
            $qty = (float) $state['quantity'];

            return [
                'attributable' => false,
                'quantities' => $qty > 0.00001
                    ? [Holding::OWNER_UNMANAGED => $qty]
                    : [],
                'lots' => [],
            ];
        }

        $quantities = [];
        $lots = [];

        foreach ($partition['lots'] as $ownerKey => $ownerTxs) {
            $state = $this->replayTransactions($ownerTxs, dryRun: true);
            $qty = (float) $state['quantity'];
            if ($qty <= 0.00001) {
                continue;
            }

            $quantities[$ownerKey] = $qty;
            $lots[$ownerKey] = $ownerTxs;
        }

        return [
            'attributable' => true,
            'quantities' => $quantities,
            'lots' => $lots,
        ];
    }

    /**
     * Partition ledger rows by owner when attribution is determinable.
     *
     * Buy: recommendation → strategy owner; else unmanaged.
     * Sell: recommendation owner when present; else the sole open owner lot if exactly one
     * has quantity; otherwise not attributable (do not invent a rule).
     *
     * A sell that would oversell its resolved owner lot is not attributable (e.g. EXIT rec
     * owned by strategy B against a manual unmanaged BUY) — fall back to blended replay.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array{attributable: bool, lots: array<string, Collection<int, Transaction>>}
     */
    public function partitionTransactionsByOwner(Collection $transactions): array
    {
        /** @var array<string, list<Transaction>> $buckets */
        $buckets = [];
        /** @var array<string, float> $openQty */
        $openQty = [];
        $attributable = true;

        foreach ($transactions as $transaction) {
            $ownerKey = $this->resolveTransactionOwnerKey($transaction, $openQty);
            if ($ownerKey === null) {
                $attributable = false;
                break;
            }

            $qty = (float) $transaction->quantity;
            if ($transaction->type !== 'buy') {
                $available = $openQty[$ownerKey] ?? 0.0;
                if ($qty > $available + 0.00001) {
                    // Resolved owner does not hold enough — do not invent cross-owner sell rules.
                    $attributable = false;
                    break;
                }
            }

            $buckets[$ownerKey] ??= [];
            $buckets[$ownerKey][] = $transaction;

            $openQty[$ownerKey] = ($openQty[$ownerKey] ?? 0.0)
                + ($transaction->type === 'buy' ? $qty : -$qty);
            if (($openQty[$ownerKey] ?? 0) <= 0.00001) {
                $openQty[$ownerKey] = 0.0;
            }
        }

        if (! $attributable) {
            return ['attributable' => false, 'lots' => []];
        }

        $lots = [];
        foreach ($buckets as $ownerKey => $txs) {
            $lots[$ownerKey] = collect($txs);
        }

        return ['attributable' => true, 'lots' => $lots];
    }

    /**
     * @param  array<string, float>  $openQty
     */
    protected function resolveTransactionOwnerKey(Transaction $transaction, array $openQty): ?string
    {
        $strategyId = $transaction->owningStrategyId();
        if ($strategyId !== null) {
            return Holding::ownerKeyFor((int) $strategyId);
        }

        if ($transaction->type === 'buy') {
            return Holding::OWNER_UNMANAGED;
        }

        // Sell without recommendation owner: only determinable when exactly one lot is open.
        $openOwners = [];
        foreach ($openQty as $key => $qty) {
            if ($qty > 0.00001) {
                $openOwners[] = $key;
            }
        }

        if (count($openOwners) === 1) {
            return $openOwners[0];
        }

        // No open lots yet, or multiple open owners — cannot invent attribution.
        if (count($openOwners) === 0) {
            return Holding::OWNER_UNMANAGED;
        }

        return null;
    }

    /**
     * @param  array<string, Collection<int, Transaction>>  $lots
     * @param  Collection<int, Transaction>  $allTransactions
     * @return Collection<int, Holding>
     */
    protected function persistAttributedLots(
        PortfolioProfile $profile,
        Stock $stock,
        array $lots,
        Collection $allTransactions,
    ): Collection {
        $persisted = collect();
        $keptOwnerKeys = [];
        $metricsReset = false;

        foreach ($lots as $ownerKey => $ownerTxs) {
            $state = $this->replayTransactions(
                $ownerTxs,
                $metricsReset ? null : $profile,
                $metricsReset ? null : $stock,
                dryRun: false,
            );
            $metricsReset = true;

            if ($state['quantity'] <= 0.00001) {
                // Full exit ends the ownership episode: clear OD-12 target/filled for this owner.
                Holding::query()
                    ->where('profile_id', $profile->id)
                    ->where('stock_id', $stock->id)
                    ->where('owner_key', $ownerKey)
                    ->update([
                        'quantity' => 0,
                        'avg_buy_price' => 0,
                        'invested_amount' => 0,
                        'filled_amount' => 0,
                        'target_amount' => null,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            $strategyId = $ownerKey === Holding::OWNER_UNMANAGED
                ? null
                : (str_starts_with($ownerKey, 'strategy:')
                    ? (int) substr($ownerKey, strlen('strategy:'))
                    : null);

            $invested = round($state['invested_amount'], 4);
            $holding = Holding::query()->updateOrCreate(
                [
                    'profile_id' => $profile->id,
                    'stock_id' => $stock->id,
                    'owner_key' => $ownerKey,
                ],
                [
                    'strategy_id' => $strategyId,
                    'quantity' => round($state['quantity'], 4),
                    'avg_buy_price' => round($state['avg_buy_price'], 4),
                    'invested_amount' => $invested,
                    // OD-12 filled = actual invested cost of open lot; never copy target.
                    'filled_amount' => $invested,
                    'total_fees' => round($state['total_fees'], 4),
                    'realized_profit' => round($state['realized_profit'], 4),
                    'updated_at' => now(),
                ],
            );

            $keptOwnerKeys[] = $ownerKey;
            $persisted->push($holding);
        }

        Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->when(
                $keptOwnerKeys !== [],
                fn ($q) => $q->whereNotIn('owner_key', $keptOwnerKeys),
                fn ($q) => $q,
            )
            ->delete();

        if ($persisted->isEmpty()) {
            // Fully exited: ensure no stale rows; return a zero unmanaged placeholder for BC.
            $this->deactivateTracking($stock);
            app(AlertExpirationService::class)->expireForProfileStockIfUnheld($profile, $stock);

            return collect([$this->persistBlendedLot($profile, $stock, $allTransactions)]);
        }

        return $persisted->values();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function persistBlendedLot(PortfolioProfile $profile, Stock $stock, Collection $transactions): Holding
    {
        $state = $this->replayTransactions($transactions, $profile, $stock);

        $existing = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->orderBy('id')
            ->get();

        $values = [
            'quantity' => round($state['quantity'], 4),
            'avg_buy_price' => round($state['avg_buy_price'], 4),
            'invested_amount' => round($state['invested_amount'], 4),
            'filled_amount' => $state['quantity'] > 0.00001 ? round($state['invested_amount'], 4) : 0,
            'total_fees' => round($state['total_fees'], 4),
            'realized_profit' => round($state['realized_profit'], 4),
            'updated_at' => now(),
        ];
        if ($state['quantity'] <= 0.00001) {
            $values['target_amount'] = null;
        }

        if ($existing->count() === 1) {
            $holding = $existing->first();
            $holding->fill($values)->save();

            return $holding;
        }

        if ($existing->isEmpty()) {
            $strategyId = $this->inferOwnerStrategyId($transactions);

            return Holding::query()->create(array_merge($values, [
                'profile_id' => $profile->id,
                'stock_id' => $stock->id,
                'strategy_id' => $strategyId,
                'owner_key' => Holding::ownerKeyFor($strategyId),
            ]));
        }

        // Multiple owner rows exist but ledger is not fully attributable: update first row
        // only; do not merge/delete other owners' rows (would invent destructive attribution).
        $holding = $existing->first();
        $holding->fill($values)->save();

        return $holding;
    }

    /**
     * Borrower/recommendation owner when the ledger for this stock is a single strategy.
     * Manual unlinked buys remain unmanaged.
     */
    protected function inferOwnerStrategyId(Collection $transactions): ?int
    {
        $ids = [];
        foreach ($transactions as $transaction) {
            $sid = $transaction->owningStrategyId();
            if ($sid === null) {
                continue;
            }
            $ids[(int) $sid] = true;
        }
        $keys = array_keys($ids);

        return count($keys) === 1 ? $keys[0] : null;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function transactionsForProfileStock(PortfolioProfile $profile, Stock $stock): Collection
    {
        return Transaction::query()
            ->with(['recommendation.strategyVersion'])
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    protected function deactivateTracking(Stock $stock): void
    {
        StockMetric::query()->updateOrCreate(
            ['stock_id' => $stock->id],
            ['tracking_active' => false, 'updated_at' => now()],
        );
    }

    protected function resetMetricsForNewEntry(PortfolioProfile $profile, Stock $stock): void
    {
        $defaultStoploss = (float) $this->profileSettings->get($profile, 'default_stoploss_percent', '10');

        StockMetric::query()->updateOrCreate(
            ['stock_id' => $stock->id],
            [
                'highest_close' => null,
                'latest_close' => null,
                'trailing_stop_price' => null,
                'tracking_active' => true,
                'stoploss_percent' => $defaultStoploss,
                'updated_at' => now(),
            ],
        );
    }
}
