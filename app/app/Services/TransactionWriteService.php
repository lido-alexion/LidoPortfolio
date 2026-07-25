<?php

namespace App\Services;

use App\Jobs\BackfillHistoricalDataJob;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * Single ledger write path for portfolio transactions.
 * Used by Transactions UI (POST /api/transactions) and TOS ExecutionEngine fills.
 */
class TransactionWriteService
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected TransactionRealizationService $realizations,
        protected PortfolioSnapshotRebuildService $snapshots,
    ) {}

    /**
     * Create a ledger transaction and apply holdings / realizations / snapshots / buy backfill.
     *
     * @param  array{
     *     type: string,
     *     quantity: float|int|string,
     *     price: float|int|string,
     *     fees?: float|int|string|null,
     *     transaction_date: string,
     *     notes?: string|null
     * }  $input
     * @param  bool  $softFailSnapshots  When true, snapshot rebuild errors are swallowed (TOS execute path).
     */
    public function create(
        PortfolioProfile $profile,
        Stock $stock,
        array $input,
        bool $softFailSnapshots = false,
    ): Transaction {
        $transaction = $this->insert($profile, $stock, $input);
        $this->applyAfterCreate($profile, $stock, $transaction, $softFailSnapshots);

        return $transaction->load('stock');
    }

    /**
     * Insert the ledger row only (no holdings/snapshot side effects).
     * Callers that also write TOS order links should wrap this in DB::transaction with those writes,
     * then call applyAfterCreate() after commit.
     *
     * @param  array{
     *     type: string,
     *     quantity: float|int|string,
     *     price: float|int|string,
     *     fees?: float|int|string|null,
     *     transaction_date: string,
     *     notes?: string|null
     * }  $input
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
        ]);
    }

    /**
     * Holdings, realizations, buy backfill, snapshot rebuild after a committed ledger insert.
     */
    public function applyAfterCreate(
        PortfolioProfile $profile,
        Stock $stock,
        Transaction $transaction,
        bool $softFailSnapshots = false,
    ): void {
        $this->holdings->recalculateForProfileStock($profile, $stock);
        $this->realizations->recalculateForProfileStock($profile, $stock);

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
     * @return array{type: string, quantity: float, price: float, fees: float, transaction_date: string, notes: ?string}
     */
    protected function normalizeInput(PortfolioProfile $profile, Stock $stock, array $input): array
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

        $price = (float) ($input['price'] ?? 0);
        if ($price <= 0) {
            throw ValidationException::withMessages([
                'price' => ['Price must be greater than zero.'],
            ]);
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

        if ($type === 'sell') {
            $available = $this->holdings->getAvailableQuantity($profile, $stock);
            if ($quantity > $available + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => ['Sell quantity cannot exceed current holding quantity.'],
                ]);
            }
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
        ];
    }
}
