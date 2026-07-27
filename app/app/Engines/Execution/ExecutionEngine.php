<?php

namespace App\Engines\Execution;

use App\Engines\Recommendation\RecommendationEngine;
use App\Models\Holding;
use App\Models\OrderTransaction;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\HoldingsCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\TransactionWriteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Execution Engine — pending execution → ledger transaction (manual or future broker).
 * Does not approve recommendations; that is RecommendationEngine.
 * Ledger fills go through TransactionWriteService (same path as POST /api/transactions).
 */
class ExecutionEngine
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected TransactionWriteService $writes,
        protected PortfolioLoggerService $logger,
        protected CashManagementService $cash,
        protected RecommendationEngine $recommendation,
    ) {}

    /**
     * After a ledger transaction is created with recommendation_id, mark the recommendation executed.
     * Safe to call when recommendation_id is null (no-op).
     */
    public function completeRecommendationFromTransaction(
        PortfolioProfile $profile,
        Transaction $transaction,
        ?User $user = null,
    ): ?TradingRecommendation {
        $recId = $transaction->recommendation_id;
        if (! $recId) {
            return null;
        }

        $recommendation = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('id', $recId)
            ->first();

        if (! $recommendation) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation not found for this portfolio.'],
            ]);
        }

        if ((int) $recommendation->security_id !== (int) $transaction->stock_id) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation security does not match transaction stock.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED
            && (int) $recommendation->executed_transaction_id === (int) $transaction->id) {
            return $recommendation;
        }

        if (! $recommendation->canExecuteManually()
            && $recommendation->status !== TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation is not pending execution (status: '.$recommendation->status.').'],
            ]);
        }

        DB::transaction(function () use ($recommendation, $transaction, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_EXECUTED,
                    'executed_at' => now(),
                ]);

            $executedAmount = round(
                ((float) $transaction->quantity * (float) $transaction->price) + (float) ($transaction->fees ?? 0),
                4,
            );
            $this->recommendation->convertReservation($recommendation, $executedAmount);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_EXECUTED,
                'executed_at' => now(),
                'executed_transaction_id' => $transaction->id,
            ])->save();

            if (empty($transaction->source)) {
                $transaction->forceFill(['source' => Transaction::SOURCE_RECOMMENDATION])->save();
            }
        });

        $this->logger->log('daily', 'ExecutionEngine', 'info', 'Recommendation completed from ledger transaction', [
            'recommendation_id' => $recommendation->id,
            'transaction_id' => $transaction->id,
            'user_id' => $user?->id,
        ]);

        return $recommendation->fresh(['security', 'executedTransaction']);
    }

    /**
     * Create a pending order (legacy / BC). Prefer recording a transaction with recommendation_id.
     * Default execute_now is false so approval is never an immediate trade.
     *
     * @param  array{side:string,quantity:float|int|string,price?:float|int|string,fees?:float|int|string,transaction_date?:string,notes?:string,recommendation_id?:int|null,execute_now?:bool,limit_price?:float|int|string|null}  $input
     * @return array{order: TradingOrder, transaction: ?Transaction, position: ?Holding}
     */
    public function recordOrder(PortfolioProfile $profile, Stock $stock, array $input): array
    {
        $side = strtolower((string) ($input['side'] ?? ''));
        if (! in_array($side, ['buy', 'sell'], true)) {
            throw ValidationException::withMessages(['side' => ['Side must be buy or sell.']]);
        }

        $quantity = (float) ($input['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be positive.'],
            ]);
        }

        $recommendation = $this->resolveRecommendation($profile, $stock, $input);
        $executeNow = array_key_exists('execute_now', $input)
            ? (bool) $input['execute_now']
            : false;

        $order = DB::transaction(function () use ($profile, $stock, $side, $quantity, $input, $recommendation) {
            return TradingOrder::query()->create([
                'profile_id' => $profile->id,
                'recommendation_id' => $recommendation?->id,
                'security_id' => $stock->id,
                'side' => $side,
                'quantity' => $quantity,
                'limit_price' => isset($input['limit_price']) ? (float) $input['limit_price'] : null,
                'order_type' => 'market',
                'notes' => $input['notes'] ?? null,
                'status' => TradingOrder::STATUS_PENDING,
            ]);
        });

        if (! $executeNow) {
            $this->logger->log('daily', 'ExecutionEngine', 'info', 'Order created pending', [
                'order_id' => $order->id,
                'symbol' => $stock->symbol,
            ]);

            return [
                'order' => $order->fresh(['security', 'recommendation']),
                'transaction' => null,
                'position' => Holding::query()
                    ->where('profile_id', $profile->id)
                    ->where('stock_id', $stock->id)
                    ->first(),
            ];
        }

        return $this->executeOrder($profile, $order, [
            'price' => $input['price'] ?? null,
            'fees' => $input['fees'] ?? 0,
            'transaction_date' => $input['transaction_date'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);
    }

    /**
     * @param  array{price:float|int|string,fees?:float|int|string,transaction_date?:string,notes?:string|null}  $input
     * @return array{order: TradingOrder, transaction: Transaction, position: ?Holding}
     */
    public function executeOrder(PortfolioProfile $profile, TradingOrder $order, array $input): array
    {
        if ((int) $order->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages(['order' => ['Order not found for this portfolio.']]);
        }

        if ($order->status !== TradingOrder::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'order' => ['Only pending orders can be executed (status: '.$order->status.').'],
            ]);
        }

        $price = (float) ($input['price'] ?? 0);
        $fees = (float) ($input['fees'] ?? 0);
        if ($price < 0) {
            throw ValidationException::withMessages(['price' => ['Price must be zero or positive.']]);
        }
        if ($price <= 0 && $order->limit_price !== null) {
            $price = (float) $order->limit_price;
        }
        if ($price <= 0) {
            throw ValidationException::withMessages(['price' => ['Execution price is required.']]);
        }

        $stock = $order->security ?? Stock::query()->findOrFail($order->security_id);
        $side = $order->side;
        $quantity = (float) $order->quantity;
        $date = $input['transaction_date'] ?? now()->toDateString();
        $recommendation = $order->recommendation;
        if ($recommendation === null && $order->recommendation_id) {
            $recommendation = TradingRecommendation::query()->find($order->recommendation_id);
        }

        $notes = $input['notes']
            ?? $order->notes
            ?? ($recommendation ? 'TOS recommendation #'.$recommendation->id : 'TOS order #'.$order->id);

        $transaction = DB::transaction(function () use ($profile, $stock, $side, $quantity, $price, $fees, $date, $notes, $order, $recommendation) {
            $transaction = $this->writes->insert($profile, $stock, [
                'type' => $side,
                'quantity' => $quantity,
                'price' => $price,
                'fees' => $fees,
                'transaction_date' => $date,
                'notes' => $notes,
                'source' => Transaction::SOURCE_RECOMMENDATION,
                'recommendation_id' => $recommendation?->id,
            ]);

            OrderTransaction::query()->create([
                'order_id' => $order->id,
                'transaction_id' => $transaction->id,
                'execution_price' => $price,
                'quantity' => $quantity,
                'charges' => $fees,
                'executed_at' => now(),
            ]);

            $order->forceFill([
                'status' => TradingOrder::STATUS_EXECUTED,
                'executed_at' => now(),
            ])->save();

            if ($recommendation && $recommendation->isActionable()) {
                $executedAmount = round($quantity * $price + $fees, 4);
                $this->recommendation->convertReservation($recommendation, $executedAmount);
                $recommendation->forceFill([
                    'status' => TradingRecommendation::STATUS_EXECUTED,
                    'executed_at' => now(),
                    'executed_transaction_id' => $transaction->id,
                ])->save();
            }

            return $transaction;
        });

        $this->writes->applyAfterCreate($profile, $stock, $transaction, softFailSnapshots: true);
        $this->cash->applyTradeTransaction($profile, $transaction);

        $position = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->first();

        $this->logger->log('daily', 'ExecutionEngine', 'info', 'Order executed', [
            'order_id' => $order->id,
            'transaction_id' => $transaction->id,
            'side' => $side,
            'symbol' => $stock->symbol,
        ]);

        return [
            'order' => $order->fresh(['security', 'recommendation']),
            'transaction' => $transaction->fresh()->load('stock'),
            'position' => $position,
        ];
    }

    public function cancelOrder(PortfolioProfile $profile, TradingOrder $order): TradingOrder
    {
        if ((int) $order->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages(['order' => ['Order not found for this portfolio.']]);
        }

        if ($order->status !== TradingOrder::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'order' => ['Only pending orders can be cancelled.'],
            ]);
        }

        $order->forceFill([
            'status' => TradingOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        $this->logger->log('daily', 'ExecutionEngine', 'info', 'Order cancelled', [
            'order_id' => $order->id,
        ]);

        return $order->fresh(['security', 'recommendation']);
    }

    /**
     * Before deleting a ledger transaction, undo recommendation completion (back to pending_execution).
     *
     * @return array{reverted: bool, order_id: ?int, recommendation_id: ?int}
     */
    public function revertLinkedFillBeforeTransactionDelete(
        PortfolioProfile $profile,
        Transaction $transaction,
        ?User $user = null,
    ): array {
        $recommendation = null;
        $order = null;

        if ($transaction->recommendation_id) {
            $recommendation = TradingRecommendation::query()
                ->forProfile($profile)
                ->where('id', $transaction->recommendation_id)
                ->first();
        }

        $link = OrderTransaction::query()
            ->with(['order.recommendation'])
            ->where('transaction_id', $transaction->id)
            ->first();

        if ($link?->order && (int) $link->order->profile_id === (int) $profile->id) {
            $order = $link->order;
            if (! $recommendation) {
                $recommendation = $order->recommendation;
            }
        }

        if (! $recommendation && ! $order) {
            return ['reverted' => false, 'order_id' => null, 'recommendation_id' => null];
        }

        $recommendationId = $recommendation?->id;

        DB::transaction(function () use ($order, $recommendation, $user, $transaction) {
            if ($order) {
                $order->forceFill([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'executed_at' => null,
                ])->save();
            }

            if ($recommendation
                && $recommendation->status === TradingRecommendation::STATUS_EXECUTED
                && $recommendation->isActionable()) {
                RecommendationReview::query()->create([
                    'recommendation_id' => $recommendation->id,
                    'user_id' => $user?->id,
                    'decision' => TradingRecommendation::DECISION_REOPENED,
                    'notes' => 'Returned to pending execution after deleting transaction #'.$transaction->id,
                    'created_at' => now(),
                ]);

                $recommendation->forceFill([
                    'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
                    'executed_at' => null,
                    'executed_transaction_id' => null,
                    'executed_amount' => null,
                    'approved_at' => $recommendation->approved_at ?? now(),
                ])->save();

                if ($recommendation->requiresCashReservation()) {
                    $this->recommendation->reserveForApproval($recommendation->fresh());
                }
            }
        });

        $this->logger->log('daily', 'ExecutionEngine', 'info', 'TOS fill reverted before transaction delete', [
            'transaction_id' => $transaction->id,
            'order_id' => $order?->id,
            'recommendation_id' => $recommendationId,
        ]);

        return [
            'reverted' => true,
            'order_id' => $order?->id,
            'recommendation_id' => $recommendationId,
        ];
    }

    public function findOrder(PortfolioProfile $profile, int $id): ?TradingOrder
    {
        return TradingOrder::query()
            ->with(['security', 'recommendation', 'orderTransactions'])
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @param  array{recommendation_id?:int|null}  $input
     */
    protected function resolveRecommendation(PortfolioProfile $profile, Stock $stock, array $input): ?TradingRecommendation
    {
        if (empty($input['recommendation_id'])) {
            return null;
        }

        $recommendation = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('id', (int) $input['recommendation_id'])
            ->first();

        if (! $recommendation) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation not found for this portfolio.'],
            ]);
        }
        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation already executed.'],
            ]);
        }
        if ($recommendation->status === TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Rejected recommendations cannot be executed.'],
            ]);
        }
        if (! $recommendation->canExecuteManually()) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Approve the recommendation before recording execution.'],
            ]);
        }
        if ((int) $recommendation->security_id !== (int) $stock->id) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation security does not match order security.'],
            ]);
        }

        return $recommendation;
    }

    /**
     * @return list<TradingOrder>
     */
    public function listOrders(PortfolioProfile $profile, int $limit = 50, ?string $status = null): array
    {
        $query = TradingOrder::query()
            ->with(['security', 'recommendation'])
            ->where('profile_id', $profile->id);

        if ($status) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return list<Holding>
     */
    public function listPositions(PortfolioProfile $profile): array
    {
        $this->holdings->recalculateForProfile($profile);

        return Holding::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->orderBy('stock_id')
            ->get()
            ->all();
    }

    /**
     * @return list<Transaction>
     */
    public function listTransactions(PortfolioProfile $profile, int $limit = 100): array
    {
        return Transaction::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}
