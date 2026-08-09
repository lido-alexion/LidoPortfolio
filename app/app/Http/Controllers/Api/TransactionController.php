<?php

namespace App\Http\Controllers\Api;

use App\Engines\Execution\ExecutionEngine;
use App\Http\Controllers\Controller;
use App\Jobs\BackfillHistoricalDataJob;
use App\Models\Holding;
use App\Models\Transaction;
use App\Services\CashManagementService;
use App\Services\HoldingsCalculationService;
use App\Services\PortfolioSnapshotRebuildService;
use App\Services\StockResolverService;
use App\Services\TransactionRealizationService;
use App\Services\TransactionWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected StockResolverService $stocks,
        protected PortfolioSnapshotRebuildService $snapshotRebuild,
        protected TransactionRealizationService $realizations,
        protected TransactionWriteService $writes,
        protected ExecutionEngine $execution,
        protected CashManagementService $cash,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $this->holdings->recalculateForProfile($profile);

        $scope = $request->input('scope', 'open');
        if (! in_array($scope, ['open', 'closed', 'all'], true)) {
            $scope = 'open';
        }

        $openStockIds = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->pluck('stock_id');

        $query = Transaction::query()
            ->with('stock')
            ->where('profile_id', $profile->id);

        if ($scope === 'open') {
            if ($openStockIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('stock_id', $openStockIds);
            }
        } elseif ($scope === 'closed') {
            if ($openStockIds->isNotEmpty()) {
                $query->whereNotIn('stock_id', $openStockIds);
            }
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('stock', function ($stockQuery) use ($like) {
                $stockQuery->where('symbol', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        $defaultPerPage = $scope === 'closed' ? 25 : 500;
        $maxPerPage = $scope === 'closed' ? 100 : 500;
        $perPage = min((int) $request->input('per_page', $defaultPerPage), $maxPerPage);

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $stock = $this->stocks->resolve($request);
        $validated = $this->validateTransaction($request);

        if (! empty($validated['recommendation_id'])) {
            $rec = \App\Models\TradingRecommendation::query()
                ->forProfile($profile)
                ->where('id', (int) $validated['recommendation_id'])
                ->first();
            if (! $rec) {
                throw ValidationException::withMessages([
                    'recommendation_id' => ['Recommendation not found for this portfolio.'],
                ]);
            }
            if ((int) $rec->security_id !== (int) $stock->id) {
                throw ValidationException::withMessages([
                    'recommendation_id' => ['Recommendation security does not match transaction stock.'],
                ]);
            }
            if (! $rec->canExecuteManually()) {
                throw ValidationException::withMessages([
                    'recommendation_id' => ['Recommendation is not pending execution (status: '.$rec->status.').'],
                ]);
            }
        }

        $transaction = $this->writes->create(
            $profile,
            $stock,
            [
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'price' => $validated['price'],
                'fees' => $validated['fees'] ?? 0,
                'transaction_date' => $validated['transaction_date'],
                'notes' => $validated['notes'] ?? null,
                'source' => $validated['source'] ?? null,
                'recommendation_id' => $validated['recommendation_id'] ?? null,
            ],
            softFailSnapshots: true,
            user: $request->user(),
            applyCash: true,
        );

        $tos = null;
        if (! empty($validated['recommendation_id'])) {
            $rec = $this->execution->completeRecommendationFromTransaction(
                $profile,
                $transaction,
                $request->user(),
            );
            if ($rec) {
                $tos = [
                    'recommendation_id' => $rec->id,
                    'recommendation_status' => $rec->status,
                ];
            }
        }

        return response()->json([
            'data' => $transaction->fresh()->load('stock'),
            'tos' => $tos,
            'cash' => $this->cash->summary($profile),
            'message' => $tos
                ? 'Transaction saved; recommendation marked executed.'
                : null,
        ], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        return response()->json(['data' => $transaction->load('stock')]);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $profile = \activePortfolio();
        $previousTransactionDate = $transaction->transaction_date;

        if (! $request->filled('stock_id') && ! $request->filled('symbol')) {
            $request->merge(['stock_id' => $transaction->stock_id]);
        }

        $stock = $this->stocks->resolve($request, allowCreate: false);
        $validated = $this->validateTransaction($request, $transaction);
        $validated['stock_id'] = $stock->id;

        if ($validated['type'] === 'sell') {
            $tempAvailable = $this->holdings->getAvailableQuantity($profile, $stock);
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
        $this->holdings->recalculateForProfileStock($profile, $stock);
        $this->realizations->recalculateForProfileStock($profile, $stock);
        BackfillHistoricalDataJob::dispatchSync($stock->id, $validated['transaction_date']);

        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $profile,
            $previousTransactionDate,
            $validated['transaction_date'],
        );

        return response()->json(['data' => $transaction->fresh()->load('stock')]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $profile = \activePortfolio();

        if ($transaction->type === 'buy') {
            try {
                $this->holdings->assertReplayValidAfterDeleting($profile, $transaction);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Cannot delete this buy transaction because remaining sell transactions would exceed your holding quantity. Delete the related sell transaction(s) first, then try again.',
                    ],
                ]);
            }
        }

        $stock = $transaction->stock;
        $deletedDate = $transaction->transaction_date;

        $tosRevert = null;
        $this->cash->reverseTradeTransaction($profile, $transaction, $request->user());

        $tosRevert = $this->execution->revertLinkedFillBeforeTransactionDelete(
            $profile,
            $transaction,
            $request->user(),
        );

        $transaction->delete();
        $this->holdings->recalculateForProfileStock($profile, $stock);
        $this->realizations->recalculateForProfileStock($profile, $stock);

        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $profile,
            $deletedDate,
            null,
        );

        $payload = ['message' => 'Transaction deleted'];
        if ($tosRevert['reverted']) {
            $payload['tos'] = [
                'order_cancelled' => true,
                'order_id' => $tosRevert['order_id'],
                'recommendation_reopened' => $tosRevert['recommendation_id'] !== null,
                'recommendation_id' => $tosRevert['recommendation_id'],
            ];
            $payload['message'] = 'Transaction deleted; linked recommendation returned to pending execution.';
        }

        return response()->json($payload);
    }

    protected function validateTransaction(Request $request, ?Transaction $transaction = null): array
    {
        $allowZeroPrice = $transaction?->corporate_action_id !== null;

        return $request->validate([
            'stock_id' => ['nullable', 'exists:portfolio_stocks,id'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'exchange' => ['nullable', 'string', 'max:10'],
            'sector' => ['nullable', 'string', 'max:100'],
            'type' => [$transaction ? 'sometimes' : 'required', 'in:buy,sell'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', $allowZeroPrice ? 'gte:0' : 'gt:0'],
            'fees' => ['nullable', 'numeric', 'gte:0'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'in:'.implode(',', Transaction::SOURCES)],
            'recommendation_id' => ['nullable', 'integer'],
        ]);
    }
}