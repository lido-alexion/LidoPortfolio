<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillHistoricalDataJob;
use App\Models\Transaction;
use App\Services\HoldingsCalculationService;
use App\Services\PortfolioSnapshotRebuildService;
use App\Services\StockResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected StockResolverService $stocks,
        protected PortfolioSnapshotRebuildService $snapshotRebuild,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->with('stock')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 50), 500));

        return response()->json($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        $stock = $this->stocks->resolve($request);
        $validated = $this->validateTransaction($request);
        $validated['stock_id'] = $stock->id;

        if ($validated['type'] === 'sell') {
            $available = $this->holdings->getAvailableQuantity($request->user(), $stock);
            if ($validated['quantity'] > $available + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => ['Sell quantity cannot exceed current holding quantity.'],
                ]);
            }
        }

        $transaction = Transaction::query()->create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        $this->holdings->recalculateForUserStock($request->user(), $stock);
        if ($validated['type'] === 'buy') {
            try {
                BackfillHistoricalDataJob::dispatchSync($stock->id, $validated['transaction_date']);
            } catch (\Throwable) {
                // Buy is saved; price sync can be retried from Holdings → OHLCV → Force sync.
            }
        }

        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $request->user(),
            null,
            $validated['transaction_date'],
        );

        return response()->json(['data' => $transaction->load('stock')], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        return response()->json(['data' => $transaction->load('stock')]);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        $previousTransactionDate = $transaction->transaction_date;

        if (! $request->filled('stock_id') && ! $request->filled('symbol')) {
            $request->merge(['stock_id' => $transaction->stock_id]);
        }

        $stock = $this->stocks->resolve($request, allowCreate: false);
        $validated = $this->validateTransaction($request, $transaction);
        $validated['stock_id'] = $stock->id;

        if ($validated['type'] === 'sell') {
            $tempAvailable = $this->holdings->getAvailableQuantity($request->user(), $stock);
            $currentQty = (float) $transaction->quantity;
            $available = $transaction->type === 'sell'
                ? $tempAvailable + $currentQty
                : $tempAvailable;

            if ($validated['quantity'] > $available + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => ['Sell quantity cannot exceed current holding quantity.'],
                ]);
            }
        }

        $transaction->update($validated);
        $stock = $transaction->stock;
        $this->holdings->recalculateForUserStock($request->user(), $stock);
        BackfillHistoricalDataJob::dispatchSync($stock->id, $validated['transaction_date']);

        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $request->user(),
            $previousTransactionDate,
            $validated['transaction_date'],
        );

        return response()->json(['data' => $transaction->fresh()->load('stock')]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);
        $stock = $transaction->stock;
        $deletedDate = $transaction->transaction_date;
        $transaction->delete();
        $this->holdings->recalculateForUserStock($request->user(), $stock);

        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $request->user(),
            $deletedDate,
            null,
        );

        return response()->json(['message' => 'Transaction deleted']);
    }

    protected function validateTransaction(Request $request, ?Transaction $transaction = null): array
    {
        return $request->validate([
            'stock_id' => ['nullable', 'exists:portfolio_stocks,id'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'exchange' => ['nullable', 'string', 'max:10'],
            'sector' => ['nullable', 'string', 'max:100'],
            'type' => [$transaction ? 'sometimes' : 'required', 'in:buy,sell'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'],
            'fees' => ['nullable', 'numeric', 'gte:0'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    protected function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        if ($transaction->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }
    }
}
