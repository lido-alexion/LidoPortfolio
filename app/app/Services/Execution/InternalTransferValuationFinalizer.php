<?php

namespace App\Services\Execution;

use App\Models\InternalExecutionTransfer;
use App\Models\StockPrice;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Services\TransactionWriteService;
use Illuminate\Support\Facades\DB;

final class InternalTransferValuationFinalizer
{
    public function __construct(protected TransactionWriteService $writes) {}

    public function finalizePending(): int
    {
        $count = 0;
        foreach (InternalExecutionTransfer::query()->where('valuation_status', 'provisional')->orderBy('id')->get() as $transfer) {
            $count += $this->finalize($transfer) ? 1 : 0;
        }

        return $count;
    }

    public function finalize(InternalExecutionTransfer $transfer): bool
    {
        $recommendationIds = [$transfer->sell_recommendation_id, $transfer->buy_recommendation_id];
        $orders = TradingOrder::query()
            ->whereIn('recommendation_id', $recommendationIds)
            ->where('created_at', '>=', $transfer->created_at)
            ->get();
        if ($orders->contains(fn (TradingOrder $order) => $order->hasInFlightBrokerOrder())) {
            return false;
        }

        $filled = $orders->filter(fn (TradingOrder $order) => (float) $order->filled_quantity > 0
            && (float) $order->average_fill_price > 0);
        if ($filled->isNotEmpty()) {
            $filledQty = (float) $filled->sum(fn (TradingOrder $order) => (float) $order->filled_quantity);
            $price = $filledQty > 0
                ? (float) $filled->sum(fn (TradingOrder $order) => (float) $order->filled_quantity * (float) $order->average_fill_price) / $filledQty
                : 0;
            $source = 'residual_wavg_fill';
        } else {
            $close = StockPrice::query()
                ->where('stock_id', $transfer->security_id)
                ->whereDate('price_date', '>=', $transfer->created_at->toDateString())
                ->orderBy('price_date')
                ->first();
            $price = (float) ($close?->close_price ?? 0);
            $source = 'official_session_close';
        }
        if ($price <= 0) {
            return false;
        }

        DB::transaction(function () use ($transfer, $price, $source): void {
            $locked = InternalExecutionTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->valuation_status !== 'provisional') {
                return;
            }
            $oldPrice = (float) $locked->provisional_unit_price;
            foreach ([$locked->sell_transaction_id, $locked->buy_transaction_id] as $transactionId) {
                $transaction = Transaction::query()->findOrFail($transactionId);
                $profile = $transaction->profile()->firstOrFail();
                $stock = $transaction->stock()->firstOrFail();
                $this->writes->updateFinancialUnit($profile, $transaction, $stock, [
                    'type' => $transaction->type,
                    'quantity' => $transaction->quantity,
                    'price' => $price,
                    'fees' => 0,
                    'transaction_date' => $transaction->transaction_date->toDateString(),
                    'notes' => $transaction->notes,
                    'source' => $transaction->source,
                    'recommendation_id' => $transaction->recommendation_id,
                    'owner_key' => $transaction->owner_key,
                ], applyCash: false);
            }
            $delta = round(((float) $locked->quantity) * ($price - $oldPrice), 4);
            foreach ([$locked->sell_recommendation_id, $locked->buy_recommendation_id] as $recommendationId) {
                $recommendation = TradingRecommendation::query()->lockForUpdate()->findOrFail($recommendationId);
                $internal = round((float) $recommendation->internal_executed_amount + $delta, 4);
                $remaining = max(0.0, round((float) $recommendation->remaining_target_amount - $delta, 4));
                $values = [
                    'internal_executed_amount' => $internal,
                    'remaining_target_amount' => $remaining,
                    'executed_amount' => round($internal + (float) $recommendation->external_executed_amount, 4),
                ];
                if ($remaining > 0.0001 && $recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
                    $values['status'] = TradingRecommendation::STATUS_PENDING_EXECUTION;
                    $values['executed_at'] = null;
                }
                $recommendation->forceFill($values)->save();
            }
            $audit = is_array($locked->audit) ? $locked->audit : [];
            $audit['valuation_finalized'] = [
                'from' => $oldPrice, 'to' => round($price, 4), 'source' => $source,
                'at' => now()->toIso8601String(),
            ];
            $locked->forceFill([
                'final_unit_price' => round($price, 4),
                'valuation_status' => 'final',
                'valuation_source' => $source,
                'finalized_at' => now(),
                'audit' => $audit,
            ])->save();
        }, 3);

        return true;
    }
}
