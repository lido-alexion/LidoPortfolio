<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockMasterSyncService;
use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class UniversePriceSyncController extends Controller
{
    public function status(Request $request, UniversePriceSyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:all_nse,nifty500'],
        ]);

        try {
            $scope = isset($validated['scope'])
                ? app(UniverseStockResolverService::class)->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $sync->status($scope),
        ]);
    }

    public function run(Request $request, UniversePriceSyncService $sync): JsonResponse
    {
        if (! $sync->isEnabled()) {
            return response()->json([
                'message' => 'Universe price sync is disabled in application config.',
                'data' => $sync->status(),
            ], 422);
        }

        if ($sync->isSyncInProgress()) {
            return response()->json([
                'message' => 'Universe price sync is already running. Wait for the current batch to finish.',
                'data' => $sync->status(),
            ], 409);
        }

        $validated = $request->validate([
            'mode' => ['nullable', 'in:daily,backfill'],
            'scope' => ['nullable', 'in:all_nse,nifty500'],
            'batch' => ['nullable', 'integer', 'min:1', 'max:200'],
            'process_all' => ['nullable', 'boolean'],
            'reset_cursor' => ['nullable', 'boolean'],
        ]);

        $resolver = app(UniverseStockResolverService::class);

        try {
            $scope = isset($validated['scope'])
                ? $resolver->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sync->markInProgress();

        try {
            @set_time_limit(0);

            $result = $sync->sync(
                mode: $validated['mode'] ?? 'daily',
                scope: $scope,
                batchSize: isset($validated['batch']) ? (int) $validated['batch'] : null,
                processAll: (bool) ($validated['process_all'] ?? false),
                resetCursor: (bool) ($validated['reset_cursor'] ?? false),
            );

            return response()->json([
                'data' => [
                    'run' => $result,
                    'status' => $sync->status($scope ?? $resolver->defaultScope()),
                ],
            ]);
        } finally {
            $sync->clearInProgress();
        }
    }

    public function syncStockMaster(StockMasterSyncService $master): JsonResponse
    {
        try {
            $stats = $master->syncStockMaster();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Stock master sync failed: '.$e->getMessage(),
            ], 500);
        }

        return response()->json(['data' => $stats]);
    }
}
