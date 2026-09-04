<?php

namespace App\Services\Execution;

use App\Models\ExecutionBatch;
use App\Models\Holding;
use App\Models\InternalExecutionTransfer;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Services\TransactionWriteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Same-symbol cross-Strategy logical matching for one Investor execution cycle. */
final class InternalRecommendationMatcher
{
    public function __construct(protected TransactionWriteService $writes) {}

    /** @param Collection<int, TradingRecommendation> $recommendations */
    public function match(ExecutionBatch $batch, Collection $recommendations): int
    {
        $matched = 0;
        foreach ($recommendations->groupBy('security_id') as $rows) {
            $sells = $rows->filter(fn (TradingRecommendation $row) => $row->orderSide() === 'sell')->values();
            $buys = $rows->filter(fn (TradingRecommendation $row) => $row->orderSide() === 'buy')->values();
            foreach ($sells as $sell) {
                foreach ($buys as $buy) {
                    $matched += $this->matchPair($batch, $sell->fresh(), $buy->fresh());
                }
            }
        }

        return $matched;
    }

    protected function matchPair(ExecutionBatch $batch, TradingRecommendation $sell, TradingRecommendation $buy): int
    {
        if ($sell->remaining_target_amount === null || $buy->remaining_target_amount === null) {
            return 0;
        }
        $price = (float) ($buy->reference_price ?: $sell->reference_price);
        if ($price <= 0) {
            return 0;
        }
        $sellerOwner = Holding::ownerKeyFor($sell->owningStrategyId());
        $available = (float) Holding::query()
            ->where('profile_id', $sell->profile_id)
            ->where('stock_id', $sell->security_id)
            ->where('owner_key', $sellerOwner)
            ->sum('quantity');
        $quantity = min(
            (int) floor((float) $sell->remaining_target_amount / $price),
            (int) floor((float) $buy->remaining_target_amount / $price),
            (int) floor($available),
        );
        if ($quantity < 1) {
            return 0;
        }

        $key = substr(hash('sha256', $batch->id.'|'.$sell->id.'|'.$buy->id.'|'.$quantity), 0, 80);

        return DB::transaction(function () use ($batch, $sell, $buy, $quantity, $price, $key): int {
            if (InternalExecutionTransfer::query()->where('idempotency_key', $key)->exists()) {
                return 0;
            }
            $lockedSell = TradingRecommendation::query()->lockForUpdate()->findOrFail($sell->id);
            $lockedBuy = TradingRecommendation::query()->lockForUpdate()->findOrFail($buy->id);
            $stock = $lockedSell->security()->firstOrFail();
            $sellProfile = $lockedSell->profile()->firstOrFail();
            $buyProfile = $lockedBuy->profile()->firstOrFail();

            $sellTx = $this->writes->createFinancialUnit($sellProfile, $stock, [
                'type' => 'sell', 'quantity' => $quantity, 'price' => $price, 'fees' => 0,
                'transaction_date' => now()->toDateString(),
                'notes' => 'Internal Strategy transfer for recommendation #'.$lockedSell->id,
                'source' => Transaction::SOURCE_RECOMMENDATION,
                'recommendation_id' => $lockedSell->id,
                'owner_key' => Holding::ownerKeyFor($lockedSell->owningStrategyId()),
            ], applyCash: false);
            $buyTx = $this->writes->createFinancialUnit($buyProfile, $stock, [
                'type' => 'buy', 'quantity' => $quantity, 'price' => $price, 'fees' => 0,
                'transaction_date' => now()->toDateString(),
                'notes' => 'Internal Strategy transfer for recommendation #'.$lockedBuy->id,
                'source' => Transaction::SOURCE_RECOMMENDATION,
                'recommendation_id' => $lockedBuy->id,
                'owner_key' => Holding::ownerKeyFor($lockedBuy->owningStrategyId()),
            ], applyCash: false);

            $amount = round($quantity * $price, 4);
            $this->recordProgress($lockedSell, $amount, $sellTx->id);
            $this->recordProgress($lockedBuy, $amount, $buyTx->id);
            InternalExecutionTransfer::query()->create([
                'execution_batch_id' => $batch->id,
                'security_id' => $stock->id,
                'sell_recommendation_id' => $lockedSell->id,
                'buy_recommendation_id' => $lockedBuy->id,
                'quantity' => $quantity,
                'provisional_unit_price' => $price,
                'valuation_status' => 'provisional',
                'valuation_source' => 'previous_close',
                'idempotency_key' => $key,
                'sell_transaction_id' => $sellTx->id,
                'buy_transaction_id' => $buyTx->id,
                'audit' => ['matched_at' => now()->toIso8601String()],
            ]);

            return $quantity;
        }, 3);
    }

    protected function recordProgress(TradingRecommendation $row, float $amount, int $transactionId): void
    {
        $internal = round((float) $row->internal_executed_amount + $amount, 4);
        $remaining = max(0.0, round((float) $row->remaining_target_amount - $amount, 4));
        $values = [
            'internal_executed_amount' => $internal,
            'remaining_target_amount' => $remaining,
            'executed_amount' => round($internal + (float) $row->external_executed_amount, 4),
        ];
        if ($remaining <= 0.0001) {
            $values += [
                'status' => TradingRecommendation::STATUS_EXECUTED,
                'executed_at' => now(),
                'executed_transaction_id' => $transactionId,
            ];
        }
        $row->forceFill($values)->save();
    }
}
