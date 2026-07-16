<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminOperationalAlertService;
use App\Services\IgnoredPriceGapService;
use App\Services\IndexPriceSyncService;
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
        AdminOperationalAlertService $alerts
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
            'all' => ['nullable', 'boolean'],
        ]);

        $resolver = app(UniverseStockResolverService::class);

        try {
            $scope = isset($validated['scope'])
                ? $resolver->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($gaps->isInProgress()) {
            return response()->json([
                'message' => 'A gap scan or fill is already running on the server.',
                'data' => [
                    'status' => $gaps->status($scope ?? $resolver->defaultScope()),
                ],
            ], 409);
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $runAll = (bool) ($validated['all'] ?? true);

        $result = $runAll
            ? $gaps->scanAll(scope: $scope)
            : $gaps->scanBatch(
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
            'all' => ['nullable', 'boolean'],
            'rescan_first' => ['nullable', 'boolean'],
        ]);

        $resolver = app(UniverseStockResolverService::class);

        try {
            $scope = isset($validated['scope'])
                ? $resolver->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($gaps->isInProgress()) {
            return response()->json([
                'message' => 'A gap scan or fill is already running on the server.',
                'data' => [
                    'status' => $gaps->status($scope ?? $resolver->defaultScope()),
                ],
            ], 409);
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $runAll = (bool) ($validated['all'] ?? true);

        $result = $runAll
            ? $gaps->fillAll(
                scope: $scope,
                rescanFirst: (bool) ($validated['rescan_first'] ?? true),
                maxStocksPerRun: isset($validated['batch']) ? (int) $validated['batch'] : null,
            )
            : $gaps->fillBatch(
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

    public function clearGapReports(Request $request, PriceHistoryGapService $gaps): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:all_equities,all_nse,nifty500'],
        ]);

        $resolver = app(UniverseStockResolverService::class);

        try {
            $scope = isset($validated['scope'])
                ? $resolver->normalizeScope($validated['scope'])
                : null;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($gaps->isInProgress()) {
            return response()->json([
                'message' => 'A gap scan or fill is already running on the server.',
                'data' => [
                    'status' => $gaps->status($scope ?? $resolver->defaultScope()),
                ],
            ], 409);
        }

        $result = $gaps->clearReports($scope);

        return response()->json([
            'data' => [
                'run' => $result,
                'status' => $gaps->status($scope ?? $resolver->defaultScope()),
            ],
        ]);
    }

    public function gapFillFailures(Request $request, PriceHistoryGapService $gaps): JsonResponse
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

        $status = $gaps->status($scope);

        return response()->json([
            'data' => [
                'last_fill' => $status['last_fill'] ?? null,
                'last_fill_failure_report' => $status['last_fill_failure_report'] ?? null,
            ],
        ]);
    }

    public function listIgnoredGaps(IgnoredPriceGapService $ignored): JsonResponse
    {
        return response()->json([
            'data' => $ignored->listForApi(),
        ]);
    }

    public function ignoreGap(Request $request, IgnoredPriceGapService $ignored): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'min:1'],
            'gap_from' => ['required', 'date'],
            'gap_to' => ['required', 'date'],
        ]);

        try {
            $row = $ignored->ignore(
                (int) $validated['stock_id'],
                $validated['gap_from'],
                $validated['gap_to'],
                $request->user()?->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $ignored->formatRow($row),
        ]);
    }

    public function removeIgnoredGap(int $id, IgnoredPriceGapService $ignored): JsonResponse
    {
        if (! $ignored->remove($id)) {
            return response()->json(['message' => 'Ignored gap not found.'], 404);
        }

        return response()->json(['data' => ['removed' => true]]);
    }

    public function indexStatus(IndexPriceSyncService $indexes): JsonResponse
    {
        return response()->json([
            'data' => $indexes->status(),
        ]);
    }

    public function runIndexes(Request $request, IndexPriceSyncService $indexes): JsonResponse
    {
        if (! $indexes->isEnabled()) {
            return response()->json([
                'message' => 'Index price sync is disabled in application config.',
                'data' => $indexes->status(),
            ], 422);
        }

        if ($indexes->isSyncInProgress()) {
            return response()->json([
                'message' => 'Index price sync is already running. Wait for the current batch to finish.',
                'data' => $indexes->status(),
            ], 409);
        }

        $validated = $request->validate([
            'mode' => ['nullable', 'in:daily,backfill'],
            'batch' => ['nullable', 'integer', 'min:1', 'max:20'],
            'process_all' => ['nullable', 'boolean'],
            'reset_cursor' => ['nullable', 'boolean'],
            'symbol' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            @set_time_limit(0);

            $symbol = strtoupper(trim((string) ($validated['symbol'] ?? '')));
            if ($symbol !== '') {
                $result = $indexes->syncOneSymbol(
                    $symbol,
                    $validated['mode'] ?? 'daily',
                );

                return response()->json([
                    'data' => [
                        'run' => $result,
                        'status' => $indexes->status(),
                    ],
                ]);
            }

            $result = $indexes->syncBatch(
                mode: $validated['mode'] ?? 'daily',
                batchSize: isset($validated['batch']) ? (int) $validated['batch'] : null,
                resetCursor: (bool) ($validated['reset_cursor'] ?? false),
                processAll: (bool) ($validated['process_all'] ?? false),
            );

            if (($result['skipped'] ?? 0) > 0 && ($result['processed'] ?? 0) === 0) {
                $statusCode = ($result['reason'] ?? '') === 'in_progress' ? 409 : 422;

                return response()->json([
                    'message' => 'Index price sync skipped: '.($result['reason'] ?? 'unknown'),
                    'data' => [
                        'run' => $result,
                        'status' => $indexes->status(),
                    ],
                ], $statusCode);
            }

            return response()->json([
                'data' => [
                    'run' => $result,
                    'status' => $indexes->status(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Index price sync failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function fillIndexGaps(Request $request, IndexPriceSyncService $indexes): JsonResponse
    {
        if (! $indexes->isEnabled()) {
            return response()->json([
                'message' => 'Index price sync is disabled in application config.',
            ], 422);
        }

        if ($indexes->isSyncInProgress()) {
            return response()->json([
                'message' => 'Index price sync is already running. Wait for the current batch to finish.',
                'data' => ['status' => $indexes->status()],
            ], 409);
        }

        $validated = $request->validate([
            'batch' => ['nullable', 'integer', 'min:1', 'max:20'],
            'reset_cursor' => ['nullable', 'boolean'],
        ]);

        try {
            @set_time_limit(0);
            $result = $indexes->fillGapsBatch(
                batchSize: isset($validated['batch']) ? (int) $validated['batch'] : null,
                resetCursor: (bool) ($validated['reset_cursor'] ?? false),
            );

            return response()->json([
                'data' => [
                    'run' => $result,
                    'status' => $indexes->status(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Index gap fill failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function resetIndexCursor(IndexPriceSyncService $indexes): JsonResponse
    {
        $indexes->resetCursor();

        return response()->json([
            'data' => $indexes->status(),
        ]);
    }
}
