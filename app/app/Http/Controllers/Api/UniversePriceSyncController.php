<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminOperationalAlertService;
use App\Services\PriceHistoryGapService;
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
            'scope' => ['nullable', 'in:all_equities,all_nse,nifty500'],
        ]);

        try {
            $scope = isset($validated['scope'])
                ? app(UniverseStockResolverService::class)->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $alerts = app(AdminOperationalAlertService::class);
        $alerts->syncActiveAlerts(false);
        $activeAlerts = $alerts->getActiveAlertsForApi();
        $unacknowledged = $alerts->getActiveAlertsForApi(false);

        return response()->json([
            'data' => array_merge($sync->status($scope), [
                'operational_alerts' => [
                    'active' => $activeAlerts,
                    'unacknowledged_count' => count($unacknowledged),
                    'admin_telegram_recipients' => $alerts->adminTelegramRecipientCount(),
                ],
            ]),
        ]);
    }

    public function acknowledgeOperationalAlert(
        Request $request,
        AdminOperationalAlertService $alerts,
    ): JsonResponse {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        if (! $alerts->acknowledge($validated['key'])) {
            return response()->json(['message' => 'Alert not found or already resolved.'], 404);
        }

        return response()->json([
            'data' => [
                'operational_alerts' => [
                    'active' => $alerts->getActiveAlertsForApi(),
                    'unacknowledged_count' => count($alerts->getActiveAlertsForApi(false)),
                ],
            ],
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
            'scope' => ['nullable', 'in:all_equities,all_nse,nifty500'],
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

        try {
            @set_time_limit(0);

            $result = $sync->sync(
                mode: $validated['mode'] ?? 'daily',
                scope: $scope,
                batchSize: isset($validated['batch']) ? (int) $validated['batch'] : null,
                processAll: (bool) ($validated['process_all'] ?? false),
                resetCursor: (bool) ($validated['reset_cursor'] ?? false),
            );

            if (($result['skipped'] ?? 0) > 0 && ($result['processed'] ?? 0) === 0) {
                return response()->json([
                    'message' => 'Universe price sync is already running. Wait for the current batch to finish.',
                    'data' => $sync->status($scope ?? $resolver->defaultScope()),
                ], 409);
            }

            return response()->json([
                'data' => [
                    'run' => $result,
                    'status' => $sync->status($scope ?? $resolver->defaultScope()),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Universe price sync failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function syncStockMaster(StockMasterSyncService $master, AdminOperationalAlertService $alerts): JsonResponse
    {
        @set_time_limit(0);

        try {
            $stats = $master->syncStockMaster(backfillNewSymbols: false);
        } catch (\Throwable $e) {
            $this->safeSyncOperationalAlerts($alerts);

            return response()->json([
                'message' => 'Stock master sync failed: '.$e->getMessage(),
            ], 500);
        }

        $this->safeSyncOperationalAlerts($alerts);

        return response()->json(['data' => $stats]);
    }

    protected function safeSyncOperationalAlerts(AdminOperationalAlertService $alerts): void
    {
        try {
            $alerts->syncAndNotify();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function gapStatus(Request $request, PriceHistoryGapService $gaps): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:all_equities,all_nse,nifty500'],
        ]);

        try {
            $scope = isset($validated['scope'])
                ? app(UniverseStockResolverService::class)->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $gaps->status($scope),
        ]);
    }

    public function scanGaps(Request $request, PriceHistoryGapService $gaps): JsonResponse
    {
        if (! $gaps->isEnabled()) {
            return response()->json([
                'message' => 'Universe price sync is disabled in application config.',
            ], 422);
        }

        $validated = $request->validate([
            'scope' => ['nullable', 'in:all_equities,all_nse,nifty500'],
            'batch' => ['nullable', 'integer', 'min:1', 'max:200'],
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

        @set_time_limit(0);

        $result = $gaps->scanBatch(
            scope: $scope,
            batchSize: isset($validated['batch']) ? (int) $validated['batch'] : null,
            resetCursor: (bool) ($validated['reset_cursor'] ?? false),
        );

        return response()->json([
            'data' => [
                'run' => $result,
                'status' => $gaps->status($scope ?? $resolver->defaultScope()),
            ],
        ]);
    }

    public function fillGaps(Request $request, PriceHistoryGapService $gaps): JsonResponse
    {
        if (! $gaps->isEnabled()) {
            return response()->json([
                'message' => 'Universe price sync is disabled in application config.',
            ], 422);
        }

        $validated = $request->validate([
            'scope' => ['nullable', 'in:all_equities,all_nse,nifty500'],
            'batch' => ['nullable', 'integer', 'min:1', 'max:200'],
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

        @set_time_limit(0);

        $result = $gaps->fillBatch(
            scope: $scope,
            batchSize: isset($validated['batch']) ? (int) $validated['batch'] : null,
            resetCursor: (bool) ($validated['reset_cursor'] ?? false),
        );

        return response()->json([
            'data' => [
                'run' => $result,
                'status' => $gaps->status($scope ?? $resolver->defaultScope()),
            ],
        ]);
    }
}
