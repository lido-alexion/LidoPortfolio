<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Data\DataEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Support\TradingOsPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataController extends Controller
{
    public function __construct(
        protected DataEngine $data,
    ) {}

    public function securities(Request $request): JsonResponse
    {
        $page = TradingOsPagination::resolve($request, TradingOsPagination::DEFAULT_PAGE_SIZE);
        $paginator = $this->data->listSecurities(
            $request->query('search'),
            $page['pageSize'],
            $page['page'],
        );

        return ApiEnvelope::success($paginator->items(), TradingOsPagination::meta($paginator));
    }

    public function securityShow(int $id): JsonResponse
    {
        $stock = $this->data->securityDetails($id);
        if (! $stock) {
            return ApiEnvelope::error('NOT_FOUND', 'Security not found.', 404);
        }

        return ApiEnvelope::success($stock);
    }

    public function priceBars(Request $request): JsonResponse
    {
        $securityId = (int) $request->query('security_id', $request->query('securityId', 0));
        if ($securityId <= 0) {
            return ApiEnvelope::error('VALIDATION_ERROR', 'security_id is required.', 422);
        }

        $page = TradingOsPagination::resolve($request, 100, TradingOsPagination::PRICE_BARS_MAX_PAGE_SIZE);
        $paginator = $this->data->queryPriceBars(
            $securityId,
            $request->query('from'),
            $request->query('to'),
            $page['pageSize'],
            $page['page'],
        );

        return ApiEnvelope::success($paginator->items(), TradingOsPagination::meta($paginator));
    }

    public function importsStore(Request $request): JsonResponse
    {
        $force = (bool) $request->boolean('force');
        $result = $this->data->triggerImport($force);

        return ApiEnvelope::success($result, [], $result['accepted'] ? 202 : 200);
    }

    public function importsShow(string $id): JsonResponse
    {
        $history = $this->data->importHistory(50);
        $match = collect($history)->firstWhere('id', $id);
        if (! $match) {
            return ApiEnvelope::error('NOT_FOUND', 'Import job not found.', 404);
        }

        return ApiEnvelope::success($match);
    }

    public function datasetStatus(): JsonResponse
    {
        return ApiEnvelope::success($this->data->datasetStatus());
    }
}
