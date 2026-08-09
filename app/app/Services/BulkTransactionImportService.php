<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Transaction;
use App\Models\TransactionImportBatch;
use App\Models\TransactionImportBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F019 all-or-nothing bulk transaction import (PD-F019-01/02/03/15/18).
 */
class BulkTransactionImportService
{
    public function __construct(
        protected TransactionWriteService $writes,
        protected StockResolverService $stocks,
        protected CashManagementService $cash,
        protected HoldingsCalculationService $holdings,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Each row: row_id, symbol|stock_id, exchange?, type, quantity, price, fees?, transaction_date, notes?
     * @return array{
     *   status: string,
     *   batch_id: string,
     *   row_count: int,
     *   data: list<Transaction>,
     *   cash: array<string, mixed>
     * }
     */
    public function commit(PortfolioProfile $profile, string $batchKey, array $rows, ?User $user = null): array
    {
        $batchKey = strtolower(trim($batchKey));
        if ($batchKey === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $batchKey)) {
            throw ValidationException::withMessages([
                'batch_id' => ['batch_id must be a valid UUID.'],
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'rows' => ['At least one row is required.'],
            ]);
        }

        $existing = TransactionImportBatch::query()
            ->where('profile_id', $profile->id)
            ->where('batch_key', $batchKey)
            ->first();

        if ($existing && $existing->isCommitted()) {
            return $this->alreadyCommittedResponse($profile, $existing);
        }

        $prepared = $this->validateAndPrepare($profile, $rows);

        $committed = DB::transaction(function () use ($profile, $batchKey, $prepared, $user) {
            $batch = TransactionImportBatch::query()->create([
                'batch_key' => $batchKey,
                'profile_id' => $profile->id,
                'user_id' => $user?->id,
                'status' => TransactionImportBatch::STATUS_COMMITTED,
                'row_count' => count($prepared),
                'committed_at' => now(),
            ]);

            $transactions = [];
            foreach ($prepared as $index => $item) {
                $transaction = $this->writes->createFinancialUnit(
                    $profile,
                    $item['stock'],
                    $item['input'],
                    $user,
                    applyCash: true,
                );

                TransactionImportBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'row_key' => $item['row_id'],
                    'sort_order' => $index,
                    'transaction_id' => $transaction->id,
                ]);

                $transactions[] = $transaction;
            }

            return [
                'batch' => $batch,
                'transactions' => $transactions,
            ];
        });

        foreach ($committed['transactions'] as $transaction) {
            $stock = $transaction->stock ?? $transaction->load('stock')->stock;
            $this->writes->applyPostCommitSideEffects($profile, $stock, $transaction, softFailSnapshots: true);
        }

        return [
            'status' => 'committed',
            'batch_id' => $batchKey,
            'row_count' => count($committed['transactions']),
            'data' => array_map(
                fn (Transaction $tx) => $tx->fresh()->load('stock'),
                $committed['transactions'],
            ),
            'cash' => $this->cash->summary($profile),
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   batch_id: string,
     *   row_count: int,
     *   data: list<Transaction>,
     *   cash: array<string, mixed>
     * }
     */
    protected function alreadyCommittedResponse(PortfolioProfile $profile, TransactionImportBatch $batch): array
    {
        $items = $batch->items()->with(['transaction.stock'])->orderBy('sort_order')->get();
        $transactions = $items->map(fn (TransactionImportBatchItem $item) => $item->transaction)->filter()->values()->all();

        return [
            'status' => 'already_committed',
            'batch_id' => $batch->batch_key,
            'row_count' => count($transactions),
            'data' => $transactions,
            'cash' => $this->cash->summary($profile),
        ];
    }

    /**
     * Validate all rows and simulate holdings + cash before any commit.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row_id: string, stock: \App\Models\Stock, input: array<string, mixed>}>
     */
    protected function validateAndPrepare(PortfolioProfile $profile, array $rows): array
    {
        $seenRowIds = [];
        $prepared = [];
        $virtualQty = [];
        $cashBalance = $this->cash->balance($profile);

        foreach ($rows as $index => $row) {
            $rowId = strtolower(trim((string) ($row['row_id'] ?? '')));
            if ($rowId === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $rowId)) {
                throw $this->rowException($index, $rowId, 'row_id', 'Each row must include a valid UUID row_id.');
            }
            if (isset($seenRowIds[$rowId])) {
                throw $this->rowException($index, $rowId, 'row_id', 'Duplicate row_id within the batch.');
            }
            $seenRowIds[$rowId] = true;

            try {
                $stock = $this->stocks->resolveFromAttributes([
                    'stock_id' => $row['stock_id'] ?? null,
                    'symbol' => $row['symbol'] ?? null,
                    'exchange' => $row['exchange'] ?? 'NSE',
                    'name' => $row['name'] ?? null,
                ], allowCreate: true);
            } catch (ValidationException $e) {
                throw $this->mapRowValidation($index, $rowId, $e);
            }

            try {
                $normalized = $this->writes->normalizeCore([
                    'type' => $row['type'] ?? null,
                    'quantity' => $row['quantity'] ?? null,
                    'price' => $row['price'] ?? null,
                    'fees' => $row['fees'] ?? 0,
                    'transaction_date' => $row['transaction_date'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'source' => Transaction::SOURCE_MANUAL,
                ]);
            } catch (ValidationException $e) {
                throw $this->mapRowValidation($index, $rowId, $e);
            }

            $stockId = (int) $stock->id;
            if (! array_key_exists($stockId, $virtualQty)) {
                $virtualQty[$stockId] = $this->holdings->getAvailableQuantity($profile, $stock);
            }

            if ($normalized['type'] === 'sell') {
                if ($normalized['quantity'] > $virtualQty[$stockId] + 0.00001) {
                    throw $this->rowException(
                        $index,
                        $rowId,
                        'quantity',
                        'Sell quantity cannot exceed current holding quantity.',
                    );
                }
                $virtualQty[$stockId] -= $normalized['quantity'];
            } else {
                $virtualQty[$stockId] += $normalized['quantity'];
            }

            $delta = $this->writes->tradeCashDelta(
                $normalized['type'],
                $normalized['quantity'],
                $normalized['price'],
                $normalized['fees'],
            );
            $cashBalance = round($cashBalance + $delta, 4);
            if ($cashBalance < -0.0001) {
                throw $this->rowException(
                    $index,
                    $rowId,
                    'amount',
                    'Insufficient cash balance for this operation.',
                );
            }

            $prepared[] = [
                'row_id' => $rowId,
                'stock' => $stock,
                'input' => $normalized,
            ];
        }

        return $prepared;
    }

    protected function rowException(int $index, string $rowId, string $field, string $message): ValidationException
    {
        $suffix = $rowId !== '' ? " (row {$rowId})" : '';

        return ValidationException::withMessages([
            "rows.{$index}.{$field}" => [$message.$suffix],
        ]);
    }

    protected function mapRowValidation(int $index, string $rowId, ValidationException $e): ValidationException
    {
        $messages = $e->errors();
        $field = array_key_first($messages) ?: 'row';
        $message = $messages[$field][0] ?? 'Invalid row.';

        return $this->rowException($index, $rowId, $field, $message);
    }
}
