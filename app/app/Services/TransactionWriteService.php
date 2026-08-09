<?php

namespace App\Services;

use App\Jobs\BackfillHistoricalDataJob;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
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

        return $transaction->load('stock');
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
     * @param  array<string, mixed>  $input
     */
    public function insert(PortfolioProfile $profile, Stock $stock, array $input): Transaction
    {
        $normalized = $this->normalizeInput($profile, $stock, $input);

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
     * @return array{type: string, quantity: float, price: float, fees: float, transaction_date: string, notes: ?string, source: string, recommendation_id: ?int, corporate_action_id: ?int}
     */
    public function normalizeInput(PortfolioProfile $profile, Stock $stock, array $input): array
    {
        $normalized = $this->normalizeCore($input);

        if ($normalized['type'] === 'sell') {
            $available = $this->holdings->getAvailableQuantity($profile, $stock);
            if ($normalized['quantity'] > $available + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => ['Sell quantity cannot exceed current holding quantity.'],
                ]);
            }
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
        if ($recommendationId !== null) {
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
}
