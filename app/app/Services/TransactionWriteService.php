<?php

namespace App\Services;

use App\Jobs\BackfillHistoricalDataJob;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ownership\SellAttributionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single ledger write path for portfolio transactions.
 * Used by Transactions UI (POST /api/transactions), F019 bulk import, and TOS ExecutionEngine fills.
 *
 * Financial unit (PD-F019-14): ledger insert + holdings/realizations + cash — one DB transaction.
 * OHLCV backfill and snapshots run after that unit commits (best-effort; must not undo financial consistency).
 */
class TransactionWriteService
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected TransactionRealizationService $realizations,
        protected PortfolioSnapshotRebuildService $snapshots,
        protected CashManagementService $cash,
        protected SellAttributionService $attribution,
    ) {}

    /**
     * Create a ledger transaction with atomic financial side effects, then best-effort post-commit work.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(
        PortfolioProfile $profile,
        Stock $stock,
        array $input,
        bool $softFailSnapshots = true,
        ?User $user = null,
        bool $applyCash = true,
    ): Transaction {
        $transaction = DB::transaction(function () use ($profile, $stock, $input, $user, $applyCash) {
            return $this->createFinancialUnit($profile, $stock, $input, $user, $applyCash);
        });

        $this->applyPostCommitSideEffects($profile, $stock, $transaction, $softFailSnapshots);
        $this->notifyProtectionAfterTrade($profile, $stock, $transaction);

        return $transaction->load('stock');
    }

    /**
     * Atomically replace an existing trade's economics (WSB-D1).
     * Reverse prior cash effect → update ledger → holdings/realizations → apply new cash.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(
        PortfolioProfile $profile,
        Transaction $transaction,
        Stock $stock,
        array $input,
        bool $softFailSnapshots = true,
        ?User $user = null,
        bool $applyCash = true,
    ): Transaction {
        $previousDate = $transaction->transaction_date?->toDateString()
            ?? (string) $transaction->getRawOriginal('transaction_date');

        $oldType = strtolower((string) $transaction->type);
        $oldQty = (float) $transaction->quantity;
        $oldPrice = (float) $transaction->price;
        $oldStockId = (int) $transaction->stock_id;

        $updated = DB::transaction(function () use ($profile, $transaction, $stock, $input, $user, $applyCash) {
            return $this->updateFinancialUnit($profile, $transaction, $stock, $input, $user, $applyCash);
        });

        if (! app()->runningUnitTests()) {
            $type = strtolower((string) $updated->type);
            $dateOnly = $updated->transaction_date?->toDateString()
                ?? (string) $updated->getRawOriginal('transaction_date');

            if ($type === 'buy') {
                try {
                    BackfillHistoricalDataJob::dispatchSync($stock->id, $dateOnly);
                } catch (\Throwable) {
                    // Buy is saved; price sync can be retried from Holdings → OHLCV → Force sync.
                }
            }

            try {
                $this->snapshots->rebuildAfterTransactionChange(
                    $profile,
                    $previousDate,
                    $dateOnly,
                );
            } catch (\Throwable $e) {
                if (! $softFailSnapshots) {
                    throw $e;
                }
            }
        }

        $material = in_array(strtolower((string) $updated->type), ['buy', 'sell'], true)
            && (
                $oldType !== strtolower((string) $updated->type)
                || abs($oldQty - (float) $updated->quantity) > 0.0001
                || abs($oldPrice - (float) $updated->price) > 0.0001
                || $oldStockId !== (int) $updated->stock_id
            );
        if ($material) {
            $this->notifyProtectionAfterTrade($profile, $stock, $updated);
        }

        return $updated->load('stock');
    }

    /**
     * Atomically reverse cash, delete ledger row, and recalculate holdings/realizations (WSB-D2).
     * Optional $beforeDelete runs inside the same DB transaction (e.g. TOS fill revert).
     * Snapshots rebuild after commit.
     *
     * @param  callable(Transaction): mixed|null  $beforeDelete
     * @return array{deleted_date: string, stock: Stock, before_delete_result: mixed}
     */
    public function delete(
        PortfolioProfile $profile,
        Transaction $transaction,
        ?User $user = null,
        ?callable $beforeDelete = null,
        bool $softFailSnapshots = true,
    ): array {
        $deletedDate = $transaction->transaction_date?->toDateString()
            ?? (string) $transaction->getRawOriginal('transaction_date');
        $stockId = (int) $transaction->stock_id;
        $deletedType = strtolower((string) $transaction->type);
        $deletedSource = $transaction->source;

        $result = DB::transaction(function () use ($profile, $transaction, $user, $beforeDelete) {
            return $this->deleteFinancialUnit($profile, $transaction, $user, $beforeDelete);
        });

        $stock = Stock::query()->findOrFail($stockId);
        try {
            $this->snapshots->rebuildAfterTransactionChange($profile, $deletedDate, null);
        } catch (\Throwable $e) {
            if (! $softFailSnapshots) {
                throw $e;
            }
        }

        if (in_array($deletedType, ['buy', 'sell'], true)) {
            $this->notifyProtectionAfterTrade($profile, $stock, type: $deletedType, source: $deletedSource);
        }

        return [
            'deleted_date' => $deletedDate,
            'stock' => $stock,
            'before_delete_result' => $result['before_delete_result'],
        ];
    }

    /**
     * Insert + holdings + realizations + optional cash. Caller must wrap in DB::transaction when composing larger units.
     *
     * @param  array<string, mixed>  $input
     */
    public function createFinancialUnit(
        PortfolioProfile $profile,
        Stock $stock,
        array $input,
        ?User $user = null,
        bool $applyCash = true,
    ): Transaction {
        $transaction = $this->insert($profile, $stock, $input);
        $this->applyLedgerDerivedState($profile, $stock);
        if ($applyCash) {
            $this->cash->applyTradeTransaction($profile, $transaction, $user);
        }

        return $transaction;
    }

    /**
     * Financial unit for update. Caller must wrap in DB::transaction when composing larger units.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateFinancialUnit(
        PortfolioProfile $profile,
        Transaction $transaction,
        Stock $stock,
        array $input,
        ?User $user = null,
        bool $applyCash = true,
    ): Transaction {
        if ((int) $transaction->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaction does not belong to this portfolio.'],
            ]);
        }

        $normalized = $this->normalizeInput($profile, $stock, $input, $transaction);
        $oldStockId = (int) $transaction->stock_id;

        if ($applyCash) {
            $this->cash->reverseTradeTransaction($profile, $transaction, $user);
        }

        $fill = [
            'stock_id' => $stock->id,
            'type' => $normalized['type'],
            'quantity' => $normalized['quantity'],
            'price' => $normalized['price'],
            'fees' => $normalized['fees'],
            'transaction_date' => $normalized['transaction_date'],
            'notes' => array_key_exists('notes', $input) ? $normalized['notes'] : $transaction->notes,
            'owner_key' => $normalized['owner_key'],
        ];
        if (array_key_exists('source', $input) || array_key_exists('recommendation_id', $input)) {
            $fill['source'] = $normalized['source'];
        }
        if (array_key_exists('recommendation_id', $input)) {
            $fill['recommendation_id'] = $normalized['recommendation_id'];
        }

        $transaction->forceFill($fill)->save();

        $transaction = $transaction->fresh();

        if ($oldStockId !== (int) $stock->id) {
            $oldStock = Stock::query()->find($oldStockId);
            if ($oldStock) {
                $this->applyLedgerDerivedState($profile, $oldStock);
            }
        }

        $this->applyLedgerDerivedState($profile, $stock);

        if ($applyCash) {
            $this->cash->applyTradeTransaction($profile, $transaction, $user);
        }

        return $transaction;
    }

    /**
     * Financial unit for delete. Caller must wrap in DB::transaction when composing larger units.
     *
     * @param  callable(Transaction): mixed|null  $beforeDelete
     * @return array{before_delete_result: mixed, stock: Stock}
     */
    public function deleteFinancialUnit(
        PortfolioProfile $profile,
        Transaction $transaction,
        ?User $user = null,
        ?callable $beforeDelete = null,
        bool $applyCash = true,
    ): array {
        if ((int) $transaction->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaction does not belong to this portfolio.'],
            ]);
        }

        $stock = $transaction->stock;
        if (! $stock) {
            $stock = Stock::query()->findOrFail((int) $transaction->stock_id);
        }

        if (strtolower((string) $transaction->type) === 'buy') {
            try {
                $this->holdings->assertReplayValidAfterDeleting($profile, $transaction);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Cannot delete this buy transaction because remaining sell transactions would exceed your holding quantity. Delete the related sell transaction(s) first, then try again.',
                    ],
                ]);
            }
        }

        if ($applyCash) {
            $this->cash->reverseTradeTransaction($profile, $transaction, $user);
        }

        $beforeDeleteResult = null;
        if ($beforeDelete !== null) {
            $beforeDeleteResult = $beforeDelete($transaction);
        }

        $transaction->delete();
        $this->applyLedgerDerivedState($profile, $stock);

        return [
            'before_delete_result' => $beforeDeleteResult,
            'stock' => $stock,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function insert(PortfolioProfile $profile, Stock $stock, array $input): Transaction
    {
        $normalized = $this->normalizeInput($profile, $stock, $input);

        $exitReason = $normalized['exit_reason'] ?? null;
        if ($exitReason === null && ! empty($normalized['recommendation_id'])) {
            $rec = TradingRecommendation::query()->find((int) $normalized['recommendation_id']);
            if ($rec !== null && strtoupper((string) $normalized['type']) === 'SELL') {
                $exitReason = $rec->primaryExitReason();
            }
        }

        return Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => $normalized['type'],
            'quantity' => $normalized['quantity'],
            'price' => $normalized['price'],
            'fees' => $normalized['fees'],
            'transaction_date' => $normalized['transaction_date'],
            'notes' => $normalized['notes'],
            'source' => $normalized['source'],
            'recommendation_id' => $normalized['recommendation_id'],
            'corporate_action_id' => $normalized['corporate_action_id'],
            'exit_reason' => $exitReason,
            'owner_key' => $normalized['owner_key'],
        ]);
    }

    public function applyLedgerDerivedState(PortfolioProfile $profile, Stock $stock): void
    {
        $this->holdings->recalculateForProfileStock($profile, $stock);
        $this->realizations->recalculateForProfileStock($profile, $stock);
    }

    /**
     * @deprecated Prefer applyLedgerDerivedState + applyPostCommitSideEffects.
     */
    public function applyAfterCreate(
        PortfolioProfile $profile,
        Stock $stock,
        Transaction $transaction,
        bool $softFailSnapshots = true,
    ): void {
        $this->applyLedgerDerivedState($profile, $stock);
        $this->applyPostCommitSideEffects($profile, $stock, $transaction, $softFailSnapshots);
    }

    public function applyPostCommitSideEffects(
        PortfolioProfile $profile,
        Stock $stock,
        Transaction $transaction,
        bool $softFailSnapshots = true,
    ): void {
        if (app()->runningUnitTests()) {
            return;
        }

        $type = strtolower((string) $transaction->type);
        $dateOnly = $transaction->transaction_date?->toDateString()
            ?? (string) $transaction->getRawOriginal('transaction_date');

        if ($type === 'buy') {
            try {
                BackfillHistoricalDataJob::dispatchSync($stock->id, $dateOnly);
            } catch (\Throwable) {
                // Buy is saved; price sync can be retried from Holdings → OHLCV → Force sync.
            }
        }

        try {
            $this->snapshots->rebuildAfterTransactionChange(
                $profile,
                null,
                $dateOnly,
            );
        } catch (\Throwable $e) {
            if (! $softFailSnapshots) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, quantity: float, price: float, fees: float, transaction_date: string, notes: ?string, source: string, recommendation_id: ?int, corporate_action_id: ?int, owner_key: string}
     */
    public function normalizeInput(
        PortfolioProfile $profile,
        Stock $stock,
        array $input,
        ?Transaction $existing = null,
    ): array {
        $normalized = $this->normalizeCore($input);

        if ($normalized['type'] === 'sell') {
            $resolved = $this->attribution->resolveForSell(
                $profile,
                $stock,
                $input,
                $normalized['quantity'],
                $existing,
            );
            $normalized['owner_key'] = $resolved['owner_key'];
        } else {
            $normalized['owner_key'] = $this->attribution->ownerKeyForBuy($profile, $input);
        }

        return $normalized;
    }

    /**
     * Field validation without holdings availability (bulk preflight uses a virtual qty map).
     *
     * @param  array<string, mixed>  $input
     * @return array{type: string, quantity: float, price: float, fees: float, transaction_date: string, notes: ?string, source: string, recommendation_id: ?int, corporate_action_id: ?int}
     */
    public function normalizeCore(array $input): array
    {
        $type = strtolower((string) ($input['type'] ?? ''));
        if (! in_array($type, ['buy', 'sell'], true)) {
            throw ValidationException::withMessages([
                'type' => ['Type must be buy or sell.'],
            ]);
        }

        $quantity = (float) ($input['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be positive.'],
            ]);
        }

        $source = strtolower((string) ($input['source'] ?? Transaction::SOURCE_MANUAL));
        if ($source === '') {
            $source = Transaction::SOURCE_MANUAL;
        }
        if (! in_array($source, Transaction::SOURCES, true)) {
            throw ValidationException::withMessages([
                'source' => ['Invalid transaction source.'],
            ]);
        }

        $recommendationId = isset($input['recommendation_id']) ? (int) $input['recommendation_id'] : null;
        if ($recommendationId !== null && $recommendationId <= 0) {
            $recommendationId = null;
        }
        // OD-10: bonus/split CA rows may carry recommendation_id for parent-owner
        // attribution without becoming SOURCE_RECOMMENDATION (price-0 bonus rules apply).
        // V4-SPEC-002: source=rights is a purchase, not a CA — with a recommendation
        // it is a normal SOURCE_RECOMMENDATION buy at the subscription price.
        if ($recommendationId !== null
            && ! in_array($source, [
                Transaction::SOURCE_BONUS,
                Transaction::SOURCE_SPLIT,
            ], true)
        ) {
            $source = Transaction::SOURCE_RECOMMENDATION;
        }

        $corporateActionId = isset($input['corporate_action_id']) ? (int) $input['corporate_action_id'] : null;
        if ($corporateActionId !== null && $corporateActionId <= 0) {
            $corporateActionId = null;
        }

        if ($source === Transaction::SOURCE_BONUS) {
            $price = 0.0;
        } else {
            $price = (float) ($input['price'] ?? 0);
            if ($price <= 0) {
                throw ValidationException::withMessages([
                    'price' => ['Price must be greater than zero.'],
                ]);
            }
        }

        $fees = (float) ($input['fees'] ?? 0);
        if ($fees < 0) {
            throw ValidationException::withMessages([
                'fees' => ['Fees must be zero or positive.'],
            ]);
        }

        $date = (string) ($input['transaction_date'] ?? '');
        if ($date === '') {
            throw ValidationException::withMessages([
                'transaction_date' => ['Transaction date is required.'],
            ]);
        }

        $dateOnly = substr($date, 0, 10);
        if ($dateOnly > now()->toDateString()) {
            throw ValidationException::withMessages([
                'transaction_date' => ['Transaction date cannot be in the future.'],
            ]);
        }

        $notes = $input['notes'] ?? null;
        if (is_string($notes) && strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'notes' => ['Notes may not be greater than 1000 characters.'],
            ]);
        }

        return [
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'fees' => $fees,
            'transaction_date' => $dateOnly,
            'notes' => is_string($notes) || $notes === null ? $notes : null,
            'source' => $source,
            'recommendation_id' => $recommendationId,
            'corporate_action_id' => $corporateActionId,
            'exit_reason' => isset($input['exit_reason']) && is_string($input['exit_reason'])
                ? $input['exit_reason']
                : null,
        ];
    }

    public function tradeCashDelta(string $type, float $quantity, float $price, float $fees): float
    {
        $notional = round($quantity * $price, 4);
        if (strtolower($type) === 'buy') {
            return -round($notional + $fees, 4);
        }

        return round($notional - $fees, 4);
    }

    protected function notifyProtectionAfterTrade(
        PortfolioProfile $profile,
        Stock $stock,
        ?Transaction $transaction = null,
        ?string $type = null,
        ?string $source = null,
    ): void {
        $type = strtolower((string) ($type ?? $transaction?->type ?? ''));
        $source = $source ?? $transaction?->source;
        if (! in_array($type, ['buy', 'sell'], true)) {
            return;
        }
        try {
            app(\App\Services\Protection\PositionProtectionService::class)
                ->afterCommittedFill($profile, $stock, $type, $source);
        } catch (\Throwable) {
            // Protection sync must not undo a committed ledger write.
        }
    }
}
