<?php

namespace App\Engines\Execution;

use App\Models\Holding;
use App\Models\OrderTransaction;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Services\HoldingsCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\PortfolioSnapshotRebuildService;
use App\Services\TransactionRealizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Execution Engine — manual order lifecycle: pending → executed | cancelled.
 * Positions remain portfolio_holdings; ledger remains portfolio_transactions.
 */
class ExecutionEngine
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected TransactionRealizationService $realizations,
        protected PortfolioSnapshotRebuildService $snapshots,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * Create a pending order (or execute immediately when execute_now=true).
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
            : true;

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

        if ($side === 'sell') {
            $available = $this->holdings->getAvailableQuantity($profile, $stock);
            if ($quantity > $available + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => ['Sell quantity cannot exceed current holding quantity.'],
                ]);
            }
        }

        return DB::transaction(function () use ($profile, $stock, $side, $quantity, $price, $fees, $date, $input, $order, $recommendation) {
            $transaction = Transaction::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $stock->id,
                'type' => $side,
                'quantity' => $quantity,
                'price' => $price,
                'fees' => $fees,
                'transaction_date' => $date,
                'notes' => $input['notes']
                    ?? $order->notes
                    ?? ($recommendation ? 'TOS order #'.$order->id.' (rec #'.$recommendation->id.')' : 'TOS order #'.$order->id),
            ]);

            $this->holdings->recalculateForProfileStock($profile, $stock);
            $this->realizations->recalculateForProfileStock($profile, $stock);

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

            if ($recommendation && $recommendation->status !== TradingRecommendation::STATUS_EXECUTED) {
                $recommendation->forceFill(['status' => TradingRecommendation::STATUS_EXECUTED])->save();
            }

            try {
                $this->snapshots->rebuildAfterTransactionChange($profile, null, $date);
            } catch (\Throwable $e) {
                $this->logger->log('daily', 'ExecutionEngine', 'warning', 'Snapshot rebuild failed: '.$e->getMessage(), [
                    'profile_id' => $profile->id,
                ]);
            }

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
                'transaction' => $transaction->fresh(),
                'position' => $position,
            ];
        });
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
            ->where('profile_id', $profile->id)
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
        if (! $recommendation->canCreateOrder()) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Accept the recommendation before creating an order.'],
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
