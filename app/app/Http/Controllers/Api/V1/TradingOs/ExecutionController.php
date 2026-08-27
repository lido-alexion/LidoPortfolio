<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Execution\ExecutionEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\StockResolverService;
use App\Support\TradingOsPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExecutionController extends Controller
{
    public function __construct(
        protected ExecutionEngine $execution,
        protected StockResolverService $stocks,
    ) {}

    public function ordersStore(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'side' => 'required|in:buy,sell,BUY,SELL',
            'quantity' => 'required|numeric|gt:0',
            'price' => 'nullable|numeric|min:0',
            'fees' => 'nullable|numeric|min:0',
            'transaction_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'recommendation_id' => 'nullable|integer',
            'security_id' => 'nullable|integer',
            'stock_id' => 'nullable|integer',
            'symbol' => 'nullable|string',
            'execute_now' => 'nullable|boolean',
            'limit_price' => 'nullable|numeric|min:0',
        ]);

        if (! empty($validated['security_id']) && empty($validated['stock_id'])) {
            $request->merge(['stock_id' => $validated['security_id']]);
        }

        $executeNow = array_key_exists('execute_now', $validated)
            ? (bool) $validated['execute_now']
            : false;

        if ($executeNow && (! isset($validated['price']) || $validated['price'] === null)) {
            return ApiEnvelope::error('VALIDATION_ERROR', 'price is required when execute_now is true.', 422);
        }

        try {
            $stock = $this->stocks->resolve($request, allowCreate: false);
        } catch (\Throwable $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        try {
            $result = $this->execution->recordOrder($profile, $stock, [
                ...$validated,
                'side' => strtolower($validated['side']),
                'execute_now' => $executeNow,
            ]);
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success([
            'order' => $result['order'],
            'transaction' => $result['transaction'],
            'position' => $result['position'],
        ], [], 201);
    }

    public function ordersIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $status = $request->query('status');

        $page = TradingOsPagination::resolve($request, TradingOsPagination::ORDERS_DEFAULT);
        $paginator = $this->execution->paginateOrders(
            $profile,
            $page['page'],
            $page['pageSize'],
            is_string($status) && $status !== '' ? $status : null,
        );

        return ApiEnvelope::success($paginator->items(), TradingOsPagination::meta($paginator));
    }

    public function ordersExecute(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $order = $this->execution->findOrder($profile, $id);
        if (! $order) {
            return ApiEnvelope::error('NOT_FOUND', 'Order not found.', 404);
        }

        $validated = $request->validate([
            'price' => 'required|numeric|gt:0',
            'fees' => 'nullable|numeric|min:0',
            'transaction_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $result = $this->execution->executeOrder($profile, $order, $validated);
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success([
            'order' => $result['order'],
            'transaction' => $result['transaction'],
            'position' => $result['position'],
        ]);
    }

    public function ordersCancel(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $order = $this->execution->findOrder($profile, $id);
        if (! $order) {
            return ApiEnvelope::error('NOT_FOUND', 'Order not found.', 404);
        }

        try {
            $cancelled = $this->execution->cancelOrder($profile, $order);
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success($cancelled);
    }

    public function transactionsIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $page = TradingOsPagination::resolve($request, TradingOsPagination::TRANSACTIONS_DEFAULT);
        $paginator = $this->execution->paginateTransactions($profile, $page['page'], $page['pageSize']);

        return ApiEnvelope::success($paginator->items(), TradingOsPagination::meta($paginator));
    }

    public function positionsIndex(): JsonResponse
    {
        $profile = \activePortfolio();
        $positions = $this->execution->listPositions($profile);

        return ApiEnvelope::success(array_map(fn ($h) => TradingOsPresenter::position($h), $positions));
    }
}
