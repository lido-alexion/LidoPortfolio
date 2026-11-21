<?php

namespace App\Services;

use App\Jobs\BackfillHistoricalDataJob;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\CashLedgerEntry;
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
    /** Rows predating this feature are deliberately excluded from DP allocation. */
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
        PortfolioProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        $this->lockLifecycleOwner($profile, $input);
        $transaction = $this->insert($profile, $stock, $input);
        // Only post-deployment, cash-backed, non-simulation writes join grouped DP.
        // Migration defaults all existing trades to unenrolled without repricing history.
        if ($applyCash && $transaction->simulation_origin === null) {
            $transaction->forceFill([
                'dp_policy_enrolled' => true,
                'grouped_fee_policy_enrolled' => true,
            ])->save();
            $this->reconcileGroupedFees($profile, [$transaction->transaction_date?->toDateString()], $user, $transaction->id);
        }
        $transaction->refresh();
        $this->reconcileDpGroup($profile, $transaction, $user, $applyCash);
        $transaction->refresh();
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
        PortfolioProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        $oldGroup = $this->dpGroupKey($transaction);
        $oldGroupedFeeDate = (string) ($transaction->transaction_date?->toDateString() ?? $transaction->getRawOriginal('transaction_date'));
        $isLegacy = ! (bool) ($transaction->dp_policy_enrolled ?? false);
        if ($isLegacy) {
            // A normal edit must not silently run historical fee recalculation.
            $normalized['fees'] = (float) $transaction->fees;
        } else {
            $normalized['fees'] = $this->authoritativeFees($profile, $stock, $normalized, $transaction->id);
        }
        if (! $applyCash && ((float) ($transaction->dp_fee_amount ?? 0) > 0 || $this->hasTradeCashEntry($profile, $transaction))) {
            throw ValidationException::withMessages([
                'transaction' => ['Cashless writes cannot alter a transaction already represented in the cash ledger.'],
            ]);
        }
        $this->lockLifecycleOwner($profile, ['owner_key' => $normalized['owner_key']]);
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
            'dp_fee_amount' => $isLegacy ? (float) ($transaction->dp_fee_amount ?? 0) : 0,
            'transaction_date' => $normalized['transaction_date'],
            'notes' => array_key_exists('notes', $input) ? $normalized['notes'] : $transaction->notes,
            'owner_key' => $normalized['owner_key'],
            'execution_exchange' => $normalized['execution_exchange'],
        ];
        if (array_key_exists('source', $input) || array_key_exists('recommendation_id', $input)) {
            $fill['source'] = $normalized['source'];
        }
        if (array_key_exists('recommendation_id', $input)) {
            $fill['recommendation_id'] = $normalized['recommendation_id'];
        }
        if (array_key_exists('simulation_origin', $input)) {
            $fill['simulation_origin'] = $normalized['simulation_origin'];
            $fill['simulation_effective_session_date'] = $normalized['simulation_effective_session_date'];
            $fill['simulation_processed_at'] = $normalized['simulation_processed_at'];
            $fill['simulation_evidence'] = $normalized['simulation_evidence'];
        }
        $fill['grouped_fee_policy_enrolled'] = (bool) ($transaction->grouped_fee_policy_enrolled ?? false);

        $transaction->forceFill($fill)->save();

        $transaction = $transaction->fresh();
        if ($applyCash) {
            $this->reconcileGroupedFees($profile, [$oldGroupedFeeDate, $transaction->transaction_date?->toDateString()], $user, $transaction->id);
        }
        $transaction->refresh();
        $this->reconcileDpGroups($profile, [$oldGroup, $this->dpGroupKey($transaction)], $user, $applyCash, $transaction->id);
        $transaction->refresh();

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

    /** Lock the exact strategy owner before creating or changing an owned holding. */
    private function lockLifecycleOwner(PortfolioProfile $profile, array $input): void
    {
        $strategyId = null;
        $recommendationId = (int) ($input['recommendation_id'] ?? 0);
        if ($recommendationId > 0) {
            $recommendation = TradingRecommendation::query()->forProfile($profile)->whereKey($recommendationId)->first();
            $strategyId = $recommendation?->owningStrategyId();
        }
        if ($strategyId === null && is_string($input['owner_key'] ?? null)
            && preg_match('/^strategy:(\\d+)$/', $input['owner_key'], $ownerMatch) === 1) {
            $strategyId = (int) $ownerMatch[1];
        }
        if ($strategyId !== null) {
            app(\App\Services\Strategy\StrategyLifecycleAdmission::class)
                ->lockForNewObligation((int) $profile->id, [$strategyId]);
        }
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

        if (! $applyCash && ((float) ($transaction->dp_fee_amount ?? 0) > 0 || $this->hasTradeCashEntry($profile, $transaction))) {
            throw ValidationException::withMessages([
                'transaction' => ['Cashless writes cannot delete a transaction already represented in the cash ledger.'],
            ]);
        }

        $stock = $transaction->stock;
        if (! $stock) {
            $stock = Stock::query()->findOrFail((int) $transaction->stock_id);
        }

        // Serialize all group reallocations for this profile before reversing cash.
        PortfolioProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

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

        $group = $this->dpGroupKey($transaction);
        $groupedFeeDate = (string) ($transaction->transaction_date?->toDateString() ?? $transaction->getRawOriginal('transaction_date'));
        $transaction->delete();
        if ($applyCash) {
            $this->reconcileGroupedFees($profile, [$groupedFeeDate], $user);
        }
        $this->reconcileDpGroups($profile, [$group], $user, $applyCash);
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
        $normalized['fees'] = $this->authoritativeFees($profile, $stock, $normalized);

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
            'execution_exchange' => $normalized['execution_exchange'],
            'type' => $normalized['type'],
            'quantity' => $normalized['quantity'],
            'price' => $normalized['price'],
            'fees' => $normalized['fees'],
            'dp_fee_amount' => 0,
            'transaction_date' => $normalized['transaction_date'],
            'notes' => $normalized['notes'],
            'source' => $normalized['source'],
            'simulation_origin' => $normalized['simulation_origin'],
            'simulation_effective_session_date' => $normalized['simulation_effective_session_date'],
            'simulation_processed_at' => $normalized['simulation_processed_at'],
            'simulation_evidence' => $normalized['simulation_evidence'],
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
        $normalized = $this->normalizeCore($input, $existing);
        if ($normalized['simulation_origin'] !== null && ! $profile->isPaper()) {
            throw ValidationException::withMessages([
                'simulation_origin' => ['Simulation transactions may only be written to a Paper portfolio.'],
            ]);
        }
        $stockExchange = strtoupper((string) ($stock->exchange ?? 'NSE'));
        if ($stockExchange === 'BSE' && empty($stock->is_dual_listed)) {
            if (($normalized['execution_exchange'] ?? 'NSE') !== 'BSE') {
                // Legacy API clients omit venue; infer BSE for genuinely BSE-only listings.
                if (! array_key_exists('execution_exchange', $input)) {
                    $normalized['execution_exchange'] = 'BSE';
                } else {
                    throw ValidationException::withMessages(['execution_exchange' => ['BSE-only stocks must use BSE execution.']]);
                }
            }
        }

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
    public function normalizeCore(array $input, ?Transaction $existing = null): array
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
        $executionExchange = strtoupper((string) ($input['execution_exchange'] ?? $existing?->execution_exchange ?? 'NSE'));
        if (! in_array($executionExchange, ['NSE', 'BSE'], true)) {
            throw ValidationException::withMessages(['execution_exchange' => ['Execution exchange must be NSE or BSE.']]);
        }
        if (strtoupper((string) ($input['exchange'] ?? '')) === 'BSE'
            && strtoupper((string) ($input['execution_exchange'] ?? $existing?->execution_exchange ?? 'BSE')) !== 'BSE') {
            throw ValidationException::withMessages(['execution_exchange' => ['BSE-only stocks must use BSE execution.']]);
        }
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

        $simulationOrigin = $input['simulation_origin'] ?? null;
        if ($simulationOrigin !== null && ! in_array($simulationOrigin, ['strategy_simulation', 'investor_intervention'], true)) {
            throw ValidationException::withMessages(['simulation_origin' => ['Invalid simulation origin.']]);
        }

        return [
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'fees' => $fees,
            'execution_exchange' => $executionExchange,
            'transaction_date' => $dateOnly,
            'notes' => is_string($notes) || $notes === null ? $notes : null,
            'source' => $source,
            'recommendation_id' => $recommendationId,
            'corporate_action_id' => $corporateActionId,
            'simulation_origin' => $simulationOrigin,
            'simulation_effective_session_date' => $simulationOrigin === null
                ? null : substr((string) ($input['simulation_effective_session_date'] ?? $dateOnly), 0, 10),
            'simulation_processed_at' => $simulationOrigin === null
                ? null : ($input['simulation_processed_at'] ?? now()),
            'simulation_evidence' => $simulationOrigin === null
                ? null : (is_array($input['simulation_evidence'] ?? null) ? $input['simulation_evidence'] : []),
            'exit_reason' => isset($input['exit_reason']) && is_string($input['exit_reason'])
                ? $input['exit_reason']
                : null,
        ];
    }

    /** Compute non-DP fees. Grouped DP is allocated to a persisted row by reconcileDpGroups. */
    public function authoritativeFees(PortfolioProfile $profile, Stock $stock, array $input, ?int $excludeTransactionId = null, bool $dpAlreadyChargedInBatch = false): float
    {
        $components = app(FeeCalculatorService::class)->componentsFromSettings();
        $components = array_values(array_filter($components, fn (array $component) => ($component['aggregation'] ?? null) !== 'isin_day_sell'));
        return app(FeeCalculatorService::class)->calculate(
            (float) $input['quantity'], (float) $input['price'], (string) $input['type'],
            (string) ($input['execution_exchange'] ?? 'NSE'), $components, (string) ($input['transaction_date'] ?? date('Y-m-d')),
        )['total'];
    }

    private function dpGroupKey(Transaction $transaction): ?string
    {
        if (strtolower((string) $transaction->type) !== 'sell'
            || $transaction->simulation_origin !== null
            || ! $transaction->exists) {
            return null;
        }
        $isin = strtoupper(trim((string) $transaction->stock?->isin));
        if ($isin === '') return null;
        return $transaction->profile_id.'|'.$isin.'|'.($transaction->transaction_date?->toDateString() ?? $transaction->getRawOriginal('transaction_date'));
    }

    private function reconcileDpGroup(PortfolioProfile $profile, Transaction $transaction, ?User $user, bool $applyCash): void
    {
        $this->reconcileDpGroups($profile, [$this->dpGroupKey($transaction)], $user, $applyCash, $transaction->id);
    }

    private function hasTradeCashEntry(PortfolioProfile $profile, Transaction $transaction): bool
    {
        return CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('transaction_id', $transaction->id)
            ->whereIn('entry_type', [CashLedgerEntry::TYPE_BUY, CashLedgerEntry::TYPE_SELL])
            ->exists();
    }

    /** Reallocate invoice-rounded percentage fees only across explicitly enrolled new cash trades. */
    private function reconcileGroupedFees(PortfolioProfile $profile, array $dates, ?User $user, ?int $reversedTransactionId = null): void
    {
        $calculator = app(FeeCalculatorService::class);
        foreach (array_unique(array_filter($dates)) as $date) {
            $rows = Transaction::query()->with('stock')->where('profile_id', $profile->id)
                ->where('grouped_fee_policy_enrolled', true)->whereNull('simulation_origin')
                ->whereDate('transaction_date', $date)->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty()) continue;
            $allocations = $calculator->calculateGrouped($rows->map(fn (Transaction $row) => [
                'id' => $row->id, 'quantity' => (float) $row->quantity, 'price' => (float) $row->price,
                'type' => (string) $row->type, 'execution_exchange' => (string) ($row->execution_exchange ?? 'NSE'),
                'transaction_date' => $date,
            ])->all());
            foreach ($rows as $index => $row) {
                $next = round((float) $allocations[$index]['total'] + (float) ($row->dp_fee_amount ?? 0), 4);
                $prior = round((float) $row->fees, 4);
                $delta = round($next - $prior, 4);
                if (abs($delta) < 0.00005) continue;
                $row->forceFill(['fees' => $next])->save();
                if ($row->id !== $reversedTransactionId && $this->hasTradeCashEntry($profile, $row)) {
                    $this->cash->postTransactionFeeAdjustment($profile, $row, $delta, $user, 'Grouped fee allocation adjustment');
                }
            }
        }
    }

    /** Keep one persisted DP owner per profile/ISIN/day; corrections are signed ledger entries. */
    private function reconcileDpGroups(PortfolioProfile $profile, array $keys, ?User $user, bool $applyCash, ?int $reversedTransactionId = null): void
    {
        // Cashless fills (internal transfer valuations), paper simulations and replays must
        // never create a DP liability or alter the live cash ledger.
        if (! $applyCash) {
            return;
        }

        $settings = app(FeeCalculatorService::class)->componentsFromSettings();
        $dpComponents = array_values(array_filter($settings, fn (array $c) => ($c['aggregation'] ?? null) === 'isin_day_sell'));
        foreach (array_unique(array_filter($keys)) as $key) {
            [$profileId, $isin, $date] = explode('|', $key, 3);
            if ((int) $profileId !== (int) $profile->id) continue;
            $rows = Transaction::query()->with('stock')->where('profile_id', $profile->id)->where('type', 'sell')
                ->whereNull('simulation_origin')
                ->where('dp_policy_enrolled', true)
                ->whereDate('transaction_date', $date)->whereHas('stock', fn ($q) => $q->whereRaw('UPPER(isin) = ?', [$isin]))
                ->where(function ($query) use ($reversedTransactionId) {
                    $ledgerSells = CashLedgerEntry::query()
                        ->where('entry_type', CashLedgerEntry::TYPE_SELL)
                        ->select('transaction_id');
                    if ($reversedTransactionId !== null) {
                        $query->whereKey($reversedTransactionId)->orWhereIn('id', $ledgerSells);
                    } else {
                        $query->whereIn('id', $ledgerSells);
                    }
                })
                ->orderByDesc('id')->lockForUpdate()->get();
            // Newest eligible row owns the single group charge. Historic rows remain untouched.
            $ownerId = $rows->first()?->id;
            $desired = 0.0;
            if ($ownerId !== null && $dpComponents !== []) {
                $desired = app(FeeCalculatorService::class)->calculate(1, 1, 'sell', 'NSE', $dpComponents)['total'];
            }
            foreach ($rows as $row) {
                $next = $row->id === $ownerId ? $desired : 0.0;
                $prior = (float) ($row->dp_fee_amount ?? 0);
                $delta = round($next - $prior, 4);
                if (abs($delta) < 0.00005) continue;
                $row->forceFill(['dp_fee_amount' => $next, 'fees' => round((float) $row->fees + $delta, 4)])->save();
                if ($row->id !== $reversedTransactionId) {
                    $this->cash->postTransactionFeeAdjustment($profile, $row, $delta, $user);
                }
            }
        }
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
