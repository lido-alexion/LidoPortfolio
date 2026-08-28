<?php

namespace App\Services\Risk;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ownership-episode helpers for portfolio SL / trailing (Phase 1).
 *
 * Filters ledger activity to a known owner when strategy_id / owner_key is set.
 * Same-stock adoption merge (V4-SPEC-001) attributes unmanaged fills onto the
 * destination Strategy owner; first-buy / trailing then follow that owner’s episode.
 */
final class OwnershipEpisodeService
{
    /**
     * First buy date of the current ownership episode for a specific holding owner.
     * When the holding is unmanaged, only unmanaged (no recommendation owner) fills apply.
     * When strategy-owned, only that strategy's recommendation-linked fills apply.
     *
     * Falls back to all stock transactions only when the holding has no owner identity
     * (legacy blended row).
     */
    public function firstBuyDateForHolding(PortfolioProfile $profile, Holding $holding, Stock $stock): ?Carbon
    {
        $transactions = $this->transactionsForHoldingOwner($profile, $holding, $stock);

        return $this->firstBuyDateFromTransactions($transactions);
    }

    /**
     * Actual BUY fills in the current ownership episode (OD-13 input).
     * Quantity remaining after subsequent sells is reflected by dropping a fully
     * exited episode; within an open episode, all buys in that episode are fills.
     *
     * @return list<array{quantity: float, price: float}>
     */
    public function fillsForCurrentEpisode(PortfolioProfile $profile, Holding $holding, Stock $stock): array
    {
        $transactions = $this->transactionsForHoldingOwner($profile, $holding, $stock);
        $quantity = 0.0;
        /** @var list<array{quantity: float, price: float}> $episodeFills */
        $episodeFills = [];

        foreach ($transactions as $transaction) {
            $qty = (float) $transaction->quantity;
            $price = (float) $transaction->price;

            if ($transaction->type === 'buy') {
                if ($quantity <= 0.00001) {
                    $episodeFills = [];
                }
                $episodeFills[] = ['quantity' => $qty, 'price' => $price];
                $quantity += $qty;
            } else {
                $quantity -= $qty;
                if ($quantity <= 0.00001) {
                    $quantity = 0;
                    $episodeFills = [];
                }
            }
        }

        return $quantity > 0.00001 ? $episodeFills : [];
    }

    /**
     * Raw daily close_price values on/after entry (OD-14). Never adjusted_close / low.
     *
     * @return list<float>
     */
    public function rawClosesSinceEntry(Stock $stock, Carbon $entryDate): array
    {
        return StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $entryDate->toDateString())
            ->orderBy('price_date')
            ->pluck('close_price')
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();
    }

    public function peakRawCloseSinceEntry(Stock $stock, Carbon $entryDate): ?float
    {
        $peak = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $entryDate->toDateString())
            ->max('close_price');

        return $peak !== null ? (float) $peak : null;
    }

    public function latestRawCloseSinceEntry(Stock $stock, Carbon $entryDate): ?float
    {
        $row = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $entryDate->toDateString())
            ->orderByDesc('price_date')
            ->first(['close_price']);

        return $row?->close_price !== null ? (float) $row->close_price : null;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function transactionsForHoldingOwner(PortfolioProfile $profile, Holding $holding, Stock $stock): Collection
    {
        $all = Transaction::query()
            ->with(['recommendation.strategyVersion'])
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $ownerKey = $holding->owner_key ?: Holding::OWNER_UNMANAGED;
        $strategyId = $holding->strategy_id !== null ? (int) $holding->strategy_id : null;

        // Legacy blended row with no owner identity: use full stock ledger.
        if ($strategyId === null && ($holding->owner_key === null || $holding->owner_key === '')) {
            return $all;
        }

        return $all->filter(function (Transaction $tx) use ($ownerKey, $strategyId) {
            $txStrategyId = $tx->owningStrategyId();
            $txOwnerKey = $txStrategyId !== null
                ? Holding::ownerKeyFor((int) $txStrategyId)
                : Holding::OWNER_UNMANAGED;

            if ($strategyId !== null) {
                return $txStrategyId !== null && (int) $txStrategyId === $strategyId;
            }

            return $txOwnerKey === $ownerKey;
        })->values();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    public function firstBuyDateFromTransactions(Collection $transactions): ?Carbon
    {
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
}
