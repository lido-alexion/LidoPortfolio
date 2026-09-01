<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AlertPolicyController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BulkTransactionImportController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\CorporateActionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataQualityController;
use App\Http\Controllers\Api\ExplorerAnalyticsController;
use App\Http\Controllers\Api\FrontendLogController;
use App\Http\Controllers\Api\HistoricalHoldingsController;
use App\Http\Controllers\Api\HoldingController;
use App\Http\Controllers\Api\IndexController;
use App\Http\Controllers\Api\InviteAcceptController;
use App\Http\Controllers\Api\KnowledgeBoardImageController;
use App\Http\Controllers\Api\KnowledgeBoardNoteController;
use App\Http\Controllers\Api\KnowledgeBoardTagController;
use App\Http\Controllers\Api\MarketDepthController;
use App\Http\Controllers\Api\OperationalAlertController;
use App\Http\Controllers\Api\PasswordResetAcceptController;
use App\Http\Controllers\Api\PasswordResetLinkController;
use App\Http\Controllers\Api\PatternScanController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\PortfolioHistoryController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ScreenerBacktestController;
use App\Http\Controllers\Api\ScreenerController;
use App\Http\Controllers\Api\ScreenerRunController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\AdminStockController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockPriceController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\SyncLogController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UniversePriceSyncController;
use App\Http\Controllers\Api\UserInviteController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\V1\AnalyticsArchitectureController;
use App\Http\Controllers\Api\V1\ArtifactRegistryController;
use App\Http\Controllers\Api\V1\BacktestController;
use App\Http\Controllers\Api\V1\CapitalAccountingController;
use App\Http\Controllers\Api\V1\CapitalLendingController;
use App\Http\Controllers\Api\V1\CapitalRecallController;
use App\Http\Controllers\Api\V1\CapitalResolutionController;
use App\Http\Controllers\Api\V1\PendingSaleProceedsController;
use App\Http\Controllers\Api\V1\RecallBridgeLoanController;
use App\Http\Controllers\Api\V1\RecallPeriodController;
use App\Http\Controllers\Api\V1\IndicatorRegistryController;
use App\Http\Controllers\Api\V1\MarketAnalysisController;
use App\Http\Controllers\Api\V1\ScreenerRegistryController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\StrategyRegistryController;
use App\Http\Controllers\Api\V1\TradingOs\AdminExecutionEntitlementController as TradingOsAdminEntitlementController;
use App\Http\Controllers\Api\V1\TradingOs\BrokerController as TradingOsBrokerController;
use App\Http\Controllers\Api\V1\TradingOs\DataController as TradingOsDataController;
use App\Http\Controllers\Api\V1\TradingOs\DiscoveryController as TradingOsDiscoveryController;
use App\Http\Controllers\Api\V1\TradingOs\EvaluationController as TradingOsEvaluationController;
use App\Http\Controllers\Api\V1\TradingOs\ExecutionController as TradingOsExecutionController;
use App\Http\Controllers\Api\V1\TradingOs\NotificationController as TradingOsNotificationController;
use App\Http\Controllers\Api\V1\TradingOs\PipelineController as TradingOsPipelineController;
use App\Http\Controllers\Api\V1\TradingOs\ProtectionController as TradingOsProtectionController;
use App\Http\Controllers\Api\V1\TradingOs\RecommendationController as TradingOsRecommendationController;
use App\Http\Controllers\Api\V1\TradingOs\ReviewController as TradingOsReviewController;
use App\Http\Controllers\Api\V1\TradingOs\TotpController as TradingOsTotpController;
use App\Http\Controllers\Api\WatchlistController;
use App\Http\Controllers\Api\WatchlistsController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::get('/invites/{token}', [InviteAcceptController::class, 'show'])
    ->middleware('throttle:login');
Route::post('/invites/accept', [InviteAcceptController::class, 'accept'])
    ->middleware('throttle:login');

Route::get('/reset-password/{token}', [PasswordResetAcceptController::class, 'show'])
    ->middleware('throttle:login');
Route::post('/reset-password/accept', [PasswordResetAcceptController::class, 'accept'])
    ->middleware('throttle:login');

// Guest-safe session probe
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/auth/csrf-token', [AuthController::class, 'csrfToken']);

Route::middleware(['auth:sanctum', 'active.portfolio'])->group(function () {
    Route::post('/logs/frontend', [FrontendLogController::class, 'store']);

    Route::get('/portfolios', [PortfolioController::class, 'index']);
    Route::post('/portfolios', [PortfolioController::class, 'store']);
    Route::get('/portfolios/{portfolio}', [PortfolioController::class, 'show']);
    Route::put('/portfolios/{portfolio}', [PortfolioController::class, 'update']);
    Route::delete('/portfolios/{portfolio}', [PortfolioController::class, 'destroy']);
    Route::post('/portfolios/{portfolio}/set-default', [PortfolioController::class, 'setDefault']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/sessions', [AuthController::class, 'sessions']);
    Route::post('/auth/sessions/logout-others', [AuthController::class, 'logoutOtherSessions']);
    Route::delete('/auth/sessions/{sessionId}', [AuthController::class, 'logoutSession']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    Route::get('/profile/photo', [ProfileController::class, 'photo']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);

    Route::get('/stocks/search', [StockController::class, 'search'])
        ->middleware('throttle:stock-search');
    Route::post('/stocks/validate', [StockController::class, 'validateSymbol'])
        ->middleware('throttle:stock-validate');
    Route::get('/stocks', [StockController::class, 'index']);
    Route::get('/stocks/{stock}', [StockController::class, 'show']);
    Route::post('/transactions/bulk', [BulkTransactionImportController::class, 'store']);
    Route::apiResource('transactions', TransactionController::class);

    Route::get('/cash', [CashController::class, 'summary']);
    Route::get('/cash/reservations', [CashController::class, 'reservations']);
    Route::get('/cash/ledger', [CashController::class, 'ledger']);
    Route::post('/cash/deposit', [CashController::class, 'deposit']);
    Route::post('/cash/withdraw', [CashController::class, 'withdraw']);
    Route::post('/cash/adjust', [CashController::class, 'adjust']);

    Route::get('/corporate-actions', [CorporateActionController::class, 'index']);
    Route::post('/corporate-actions/preview', [CorporateActionController::class, 'preview']);
    Route::post('/corporate-actions', [CorporateActionController::class, 'store']);

    Route::get('/holdings', [HoldingController::class, 'index']);
    Route::post('/holdings/{holding}/adopt', [HoldingController::class, 'adopt']);
    Route::get('/watchlists', [WatchlistsController::class, 'index']);
    Route::post('/watchlists', [WatchlistsController::class, 'store']);
    Route::put('/watchlists/{watchlist}', [WatchlistsController::class, 'update']);
    Route::delete('/watchlists/{watchlist}', [WatchlistsController::class, 'destroy']);
    Route::get('/watchlists/{watchlist}/items', [WatchlistController::class, 'index']);
    Route::post('/watchlists/{watchlist}/items', [WatchlistController::class, 'store']);
    Route::get('/watchlist/membership', [WatchlistController::class, 'membership']);
    Route::put('/watchlist-items/{watchlistItem}', [WatchlistController::class, 'update']);
    Route::delete('/watchlist-items/{watchlistItem}', [WatchlistController::class, 'destroy']);
    Route::get('/stocks/{stock}/prices', [StockPriceController::class, 'index']);
    Route::get('/stocks/{stock}/market-prices', [StockPriceController::class, 'market']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/market-depth', [MarketDepthController::class, 'show']);
    Route::get('/patterns/scan', [PatternScanController::class, 'index']);
    Route::get('/stocks/{stock}/pattern-scan', [PatternScanController::class, 'stock']);

    Route::prefix('calendar')->group(function () {
        Route::get('/events', [CalendarEventController::class, 'index']);
        Route::get('/occurrences', [CalendarEventController::class, 'occurrences']);
        Route::get('/upcoming', [CalendarEventController::class, 'upcoming']);
        Route::post('/events', [CalendarEventController::class, 'store']);
        Route::put('/events/{calendarEvent}', [CalendarEventController::class, 'update']);
        Route::delete('/events/{calendarEvent}', [CalendarEventController::class, 'destroy']);
    });

    Route::prefix('knowledge-board')->group(function () {
        Route::post('/images', [KnowledgeBoardImageController::class, 'store']);
        Route::get('/images/{knowledgeImage}', [KnowledgeBoardImageController::class, 'show']);
        Route::get('/images/{knowledgeImage}/full', [KnowledgeBoardImageController::class, 'full']);

        Route::get('/palettes', [KnowledgeBoardNoteController::class, 'palettes']);
        Route::get('/notes', [KnowledgeBoardNoteController::class, 'index']);
        Route::post('/notes/bulk', [KnowledgeBoardNoteController::class, 'bulk']);
        Route::post('/notes', [KnowledgeBoardNoteController::class, 'store']);
        Route::get('/notes/{knowledgeNote}', [KnowledgeBoardNoteController::class, 'show']);
        Route::put('/notes/{knowledgeNote}', [KnowledgeBoardNoteController::class, 'update']);
        Route::delete('/notes/{knowledgeNote}', [KnowledgeBoardNoteController::class, 'destroy']);
        Route::post('/notes/{knowledgeNote}/duplicate', [KnowledgeBoardNoteController::class, 'duplicate']);

        Route::get('/tags', [KnowledgeBoardTagController::class, 'index']);
        Route::post('/tags/merge', [KnowledgeBoardTagController::class, 'merge']);
        Route::post('/tags', [KnowledgeBoardTagController::class, 'store']);
        Route::put('/tags/{knowledgeTag}', [KnowledgeBoardTagController::class, 'update']);
        Route::delete('/tags/{knowledgeTag}', [KnowledgeBoardTagController::class, 'destroy']);
    });

    Route::post('/portfolio/rebuild-history', [PortfolioHistoryController::class, 'rebuild']);
    Route::get('/portfolio/snapshots', [PortfolioHistoryController::class, 'snapshots']);
    Route::get('/portfolio/historical-holdings', [HistoricalHoldingsController::class, 'show']);
    Route::get('/analytics/portfolio', [AnalyticsController::class, 'portfolio']);
    Route::get('/analytics/stocks/{stock}', [AnalyticsController::class, 'stock']);
    Route::post('/analytics/explore', [ExplorerAnalyticsController::class, 'analyze'])
        ->middleware('throttle:analytics-explore');

    Route::get('/indexes', [IndexController::class, 'index']);
    Route::get('/indexes/page', [IndexController::class, 'page']);
    Route::get('/indexes/comparison', [IndexController::class, 'comparison']);
    Route::get('/indexes/{symbol}/constituents', [IndexController::class, 'constituents']);

    Route::get('/alerts', [AlertController::class, 'index']);
    Route::post('/alerts/expire-all', [AlertController::class, 'expireAll']);
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);

    Route::get('/alert-policies/meta', [AlertPolicyController::class, 'meta']);
    Route::post('/alert-policies/evaluate', [AlertPolicyController::class, 'evaluate']);
    Route::apiResource('alert-policies', AlertPolicyController::class);

    Route::get('/screeners/meta', [ScreenerController::class, 'meta']);
    Route::get('/screeners/shared', [ScreenerController::class, 'shared']);
    Route::post('/screeners/shared/{sourceId}/import', [ScreenerController::class, 'importShared']);
    Route::get('/screeners', [ScreenerController::class, 'index']);
    Route::post('/screeners', [ScreenerController::class, 'store']);
    Route::get('/screeners/{screener}', [ScreenerController::class, 'show']);
    Route::put('/screeners/{screener}', [ScreenerController::class, 'update']);
    Route::delete('/screeners/{screener}', [ScreenerController::class, 'destroy']);
    Route::post('/screeners/{screener}/run', [ScreenerController::class, 'run']);
    Route::post('/screeners/{screener}/backtest', [ScreenerBacktestController::class, 'start']);
    Route::get('/screeners/{screener}/backtest/matrix', [ScreenerBacktestController::class, 'screenerMatrix']);
    Route::get('/screeners/{screener}/runs/compare', [ScreenerController::class, 'compareRuns']);
    Route::get('/screeners/{screener}/runs', [ScreenerController::class, 'runs']);
    Route::delete('/screeners/{screener}/runs', [ScreenerController::class, 'clearRuns']);
    Route::get('/screener-runs/{screenerRun}', [ScreenerRunController::class, 'show']);
    Route::post('/screener-runs/{screenerRun}/continue', [ScreenerRunController::class, 'continue']);
    Route::post('/screener-backtests/{screenerBacktest}/continue', [ScreenerBacktestController::class, 'continue']);
    Route::get('/screener-backtests/{screenerBacktest}/matrix', [ScreenerBacktestController::class, 'matrix']);
    Route::delete('/screener-backtests/session/{token}', [ScreenerBacktestController::class, 'discardSession']);

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/test-telegram', [SettingsController::class, 'testTelegram']);

    Route::middleware('admin')->group(function () {
        Route::get('/admin/stocks', [AdminStockController::class, 'index']);
        Route::post('/stocks/{stock}/activate', [AdminStockController::class, 'activate']);
        Route::post('/stocks/{stock}/deactivate', [AdminStockController::class, 'deactivate']);

        Route::post('/stocks', [StockController::class, 'store']);
        Route::put('/stocks/{stock}', [StockController::class, 'update']);

        Route::post('/sync/daily', [SyncController::class, 'daily']);
        Route::post('/sync/backfill/{stock}', [SyncController::class, 'backfill']);

        Route::get('/operational-alerts', [OperationalAlertController::class, 'index']);
        Route::post('/operational-alerts/acknowledge', [OperationalAlertController::class, 'acknowledge']);
        Route::post('/operational-alerts/acknowledge-all', [OperationalAlertController::class, 'acknowledgeAll']);
        Route::post('/operational-alerts/clear', [OperationalAlertController::class, 'clear']);
        Route::post('/operational-alerts/clear-dismissed', [OperationalAlertController::class, 'clearDismissed']);
        Route::post('/operational-alerts/run-check', [OperationalAlertController::class, 'runCheck']);

        Route::get('/universe-price-sync/status', [UniversePriceSyncController::class, 'status']);
        Route::post('/universe-price-sync/operational-alerts/acknowledge', [UniversePriceSyncController::class, 'acknowledgeOperationalAlert']);
        Route::post('/universe-price-sync/run', [UniversePriceSyncController::class, 'run'])
            ->middleware('throttle:universe-price-sync');
        Route::post('/universe-price-sync/stock-master', [UniversePriceSyncController::class, 'syncStockMaster'])
            ->middleware('throttle:universe-price-sync');

        Route::get('/universe-price-sync/gaps/status', [UniversePriceSyncController::class, 'gapStatus']);
        Route::post('/universe-price-sync/gaps/scan', [UniversePriceSyncController::class, 'scanGaps'])
            ->middleware('throttle:universe-price-sync');
        Route::post('/universe-price-sync/gaps/fill', [UniversePriceSyncController::class, 'fillGaps'])
            ->middleware('throttle:universe-price-sync');
        Route::post('/universe-price-sync/gaps/clear', [UniversePriceSyncController::class, 'clearGapReports'])
            ->middleware('throttle:universe-price-sync');
        Route::get('/universe-price-sync/gaps/failures', [UniversePriceSyncController::class, 'gapFillFailures']);
        Route::get('/universe-price-sync/gaps/ignored', [UniversePriceSyncController::class, 'listIgnoredGaps']);
        Route::post('/universe-price-sync/gaps/ignore', [UniversePriceSyncController::class, 'ignoreGap'])
            ->middleware('throttle:universe-price-sync');
        Route::delete('/universe-price-sync/gaps/ignored/{id}', [UniversePriceSyncController::class, 'removeIgnoredGap']);

        Route::get('/universe-price-sync/indexes/status', [UniversePriceSyncController::class, 'indexStatus']);
        Route::post('/universe-price-sync/indexes/run', [UniversePriceSyncController::class, 'runIndexes'])
            ->middleware('throttle:universe-price-sync');
        Route::post('/universe-price-sync/indexes/fill-gaps', [UniversePriceSyncController::class, 'fillIndexGaps'])
            ->middleware('throttle:universe-price-sync');
        Route::post('/universe-price-sync/indexes/reset-cursor', [UniversePriceSyncController::class, 'resetIndexCursor'])
            ->middleware('throttle:universe-price-sync');

        Route::get('/sync-logs', [SyncLogController::class, 'index']);
        Route::get('/sync-logs/runs', [SyncLogController::class, 'runs']);
        Route::get('/sync-logs/export', [SyncLogController::class, 'export']);

        Route::get('/users', [UserManagementController::class, 'index']);
        Route::put('/users/{user}/admin', [UserManagementController::class, 'updateAdmin']);

        Route::get('/invites', [UserInviteController::class, 'index']);
        Route::post('/invites', [UserInviteController::class, 'store']);
        Route::post('/invites/{invite}/regenerate', [UserInviteController::class, 'regenerate']);
        Route::delete('/invites/{invite}', [UserInviteController::class, 'destroy']);

        Route::get('/password-reset-links', [PasswordResetLinkController::class, 'index']);
        Route::post('/password-reset-links', [PasswordResetLinkController::class, 'store']);
        Route::post('/password-reset-links/{passwordResetLink}/regenerate', [PasswordResetLinkController::class, 'regenerate']);
        Route::delete('/password-reset-links/{passwordResetLink}', [PasswordResetLinkController::class, 'destroy']);

        Route::get('/data-quality/dashboard', [DataQualityController::class, 'dashboard']);
        Route::get('/data-quality/issues/unresolved', [DataQualityController::class, 'unresolved']);
        Route::get('/data-quality/issues/history', [DataQualityController::class, 'history']);
        Route::get('/data-quality/issues/{issue}', [DataQualityController::class, 'show']);
        Route::post('/data-quality/issues/{issue}/accept', [DataQualityController::class, 'accept']);
        Route::post('/data-quality/issues/{issue}/reject', [DataQualityController::class, 'reject']);
    });
});

/*
| Trading Operating System REST API (specs/engines/REST-API-Specification.md).
| Additive /api/v1 surface; legacy /api/* routes above are unchanged.
| Auth: Sanctum session (existing SPA) rather than JWT.
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'active.portfolio'])->group(function () {
    Route::get('/securities', [TradingOsDataController::class, 'securities']);
    Route::get('/securities/{id}', [TradingOsDataController::class, 'securityShow'])->whereNumber('id');
    Route::get('/price-bars', [TradingOsDataController::class, 'priceBars']);
    Route::get('/dataset/status', [TradingOsDataController::class, 'datasetStatus']);
    Route::post('/imports', [TradingOsDataController::class, 'importsStore']);
    Route::get('/imports/{id}', [TradingOsDataController::class, 'importsShow']);

    Route::post('/discovery/runs', [TradingOsDiscoveryController::class, 'discoveryRunsStore']);
    Route::get('/candidates', [TradingOsDiscoveryController::class, 'candidates']);

    Route::post('/evaluation/runs', [TradingOsEvaluationController::class, 'evaluationRunsStore']);
    Route::get('/evaluations', [TradingOsEvaluationController::class, 'evaluations']);

    Route::post('/recommendations/generate', [TradingOsRecommendationController::class, 'recommendationsGenerate']);
    Route::get('/recommendations', [TradingOsRecommendationController::class, 'recommendationsIndex']);
    Route::get('/recommendations/pending-execution', [TradingOsRecommendationController::class, 'recommendationsPendingExecution']);
    Route::get('/recommendations/{id}', [TradingOsRecommendationController::class, 'recommendationsShow'])->whereNumber('id');
    Route::post('/recommendations/{id}/review', [TradingOsRecommendationController::class, 'recommendationsReview'])->whereNumber('id');
    Route::post('/recommendations/{id}/reopen', [TradingOsRecommendationController::class, 'recommendationsReopen'])->whereNumber('id');
    Route::post('/recommendations/{id}/cancel-execution', [TradingOsRecommendationController::class, 'recommendationsCancelExecution'])->whereNumber('id');
    Route::post('/recommendations/{id}/expire', [TradingOsRecommendationController::class, 'recommendationsExpire'])->whereNumber('id');
    Route::get('/recommendations/{id}/reviews', [TradingOsRecommendationController::class, 'recommendationsReviewHistory'])->whereNumber('id');

    Route::get('/notifications', [TradingOsNotificationController::class, 'notificationsIndex']);
    Route::post('/notifications/{id}/retry', [TradingOsNotificationController::class, 'notificationsRetry'])->whereNumber('id');

    Route::post('/orders', [TradingOsExecutionController::class, 'ordersStore']);
    Route::get('/orders', [TradingOsExecutionController::class, 'ordersIndex']);
    Route::post('/orders/{id}/execute', [TradingOsExecutionController::class, 'ordersExecute'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [TradingOsExecutionController::class, 'ordersCancel'])->whereNumber('id');
    Route::get('/transactions', [TradingOsExecutionController::class, 'transactionsIndex']);
    Route::get('/positions', [TradingOsExecutionController::class, 'positionsIndex']);
    Route::get('/execution/mode', [TradingOsExecutionController::class, 'executionModeShow']);
    Route::put('/execution/mode', [TradingOsExecutionController::class, 'executionModeUpdate']);
    Route::post('/execution/submit-selected', [TradingOsExecutionController::class, 'submitSelected']);
    Route::post('/orders/{id}/reconcile', [TradingOsExecutionController::class, 'ordersReconcile'])->whereNumber('id');

    Route::get('/protections', [TradingOsProtectionController::class, 'index']);
    Route::post('/protections', [TradingOsProtectionController::class, 'store']);
    Route::get('/protections/{id}', [TradingOsProtectionController::class, 'show'])->whereNumber('id');
    Route::post('/protections/{id}/cancel', [TradingOsProtectionController::class, 'cancel'])->whereNumber('id');
    Route::post('/protections/{id}/reconcile', [TradingOsProtectionController::class, 'reconcile'])->whereNumber('id');

    Route::get('/totp', [TradingOsTotpController::class, 'status']);
    Route::post('/totp/begin', [TradingOsTotpController::class, 'begin']);
    Route::post('/totp/confirm', [TradingOsTotpController::class, 'confirm']);
    Route::post('/totp/verify', [TradingOsTotpController::class, 'verify']);
    Route::post('/totp/recover', [TradingOsTotpController::class, 'recover']);
    Route::post('/totp/disable', [TradingOsTotpController::class, 'disable']);

    Route::get('/broker/status', [TradingOsBrokerController::class, 'status']);
    Route::get('/broker/kite/login-url', [TradingOsBrokerController::class, 'kiteLoginUrl']);
    Route::get('/broker/kite/callback', [TradingOsBrokerController::class, 'kiteCallback']);
    Route::post('/broker/kite/session', [TradingOsBrokerController::class, 'kiteSession']);
    Route::post('/broker/kite/disconnect', [TradingOsBrokerController::class, 'disconnect']);

    Route::middleware('admin')->group(function () {
        Route::put('/admin/users/{user}/automated-execution-entitlement', [TradingOsAdminEntitlementController::class, 'update']);
    });

    Route::post('/reviews/generate', [TradingOsReviewController::class, 'reviewsGenerate']);
    Route::get('/reviews', [TradingOsReviewController::class, 'reviewsIndex']);
    Route::get('/reviews/{id}', [TradingOsReviewController::class, 'reviewsShow'])->whereNumber('id');
    Route::get('/review/dashboard', [TradingOsReviewController::class, 'reviewDashboard']);
    Route::get('/review/outcomes', [TradingOsReviewController::class, 'reviewOutcomes']);

    Route::post('/pipeline/run', [TradingOsPipelineController::class, 'pipelineRun']);

    Route::get('/analytics/portfolio', [AnalyticsArchitectureController::class, 'portfolio']);
    Route::get('/analytics/market', [AnalyticsArchitectureController::class, 'market']);
    Route::get('/analytics/dashboard', [AnalyticsArchitectureController::class, 'dashboardBundle']);
    Route::get('/analytics/stocks/{stock}', [AnalyticsArchitectureController::class, 'stock']);
    Route::get('/analytics/stocks/{stock}/evaluation-profile', [AnalyticsArchitectureController::class, 'evaluationProfile']);
    Route::get('/analytics/stocks/{stock}/recommendation-preview', [AnalyticsArchitectureController::class, 'recommendationPreview']);
    Route::get('/analytics/stocks/{stock}/research', [AnalyticsArchitectureController::class, 'watchlistResearch']);

    Route::get('/market-analysis', [MarketAnalysisController::class, 'latest']);
    Route::get('/market-analysis/sentiment', [MarketAnalysisController::class, 'sentiment']);
    Route::get('/market-analysis/phase', [MarketAnalysisController::class, 'phase']);
    Route::get('/market-analysis/history', [MarketAnalysisController::class, 'history']);
    Route::get('/market-analysis/timeline', [MarketAnalysisController::class, 'timeline']);
    Route::get('/market-analysis/explainability', [MarketAnalysisController::class, 'explainability']);

    Route::get('/strategy', [StrategyController::class, 'active']);
    Route::get('/strategy/summary', [StrategyController::class, 'summary']);
    Route::get('/strategy/catalogue', [StrategyController::class, 'catalogue']);
    Route::put('/strategy', [StrategyController::class, 'update']);
    Route::put('/strategy/screeners', [StrategyController::class, 'assignScreeners']);
    Route::get('/strategy/eligibility', [StrategyController::class, 'eligibility']);
    Route::get('/strategy/scoring', [StrategyController::class, 'scoring']);
    Route::get('/strategy/exit', [StrategyController::class, 'exitStrategy']);
    Route::get('/strategy/factors', [StrategyController::class, 'factors']);
    Route::get('/strategy/indicators', [StrategyController::class, 'factors']);
    Route::get('/strategy/thresholds', [StrategyController::class, 'thresholds']);
    Route::get('/strategy/portfolio-rules', [StrategyController::class, 'portfolioRules']);
    Route::get('/strategy/capital-allocation', [StrategyController::class, 'capitalAllocation']);
    Route::get('/strategy/recommendation-rules', [StrategyController::class, 'recommendationRules']);

    Route::get('/capital', [CapitalAccountingController::class, 'show']);
    Route::put('/capital/allocations', [CapitalAccountingController::class, 'updateAllocations']);
    Route::put('/capital/reserve-pct', [CapitalAccountingController::class, 'updateReservePct']);
    Route::get('/capital/requests/{capitalRequest}/lenders', [CapitalLendingController::class, 'lenders'])->whereNumber('capitalRequest');
    Route::post('/capital/requests/{capitalRequest}/approve', [CapitalLendingController::class, 'approve'])->whereNumber('capitalRequest');
    Route::post('/capital/requests/{capitalRequest}/reject', [CapitalLendingController::class, 'reject'])->whereNumber('capitalRequest');

    // V3 WS4 Phase 3A — Recall / Bridge / Proceeds / Capital resolution APIs
    Route::get('/capital/recall-period', [RecallPeriodController::class, 'show']);
    Route::put('/capital/recall-period', [RecallPeriodController::class, 'update']);
    Route::get('/capital/recalls', [CapitalRecallController::class, 'index']);
    Route::post('/capital/recalls', [CapitalRecallController::class, 'store']);
    Route::get('/capital/recalls/{recall}', [CapitalRecallController::class, 'show'])->whereNumber('recall');
    Route::get('/capital/bridge-loans', [RecallBridgeLoanController::class, 'index']);
    Route::post('/capital/bridge-loans', [RecallBridgeLoanController::class, 'store']);
    Route::get('/capital/bridge-loans/{bridgeLoan}', [RecallBridgeLoanController::class, 'show'])->whereNumber('bridgeLoan');
    Route::get('/capital/pending-sale-proceeds', [PendingSaleProceedsController::class, 'index']);
    Route::get('/capital/pending-sale-proceeds/{proceeds}', [PendingSaleProceedsController::class, 'show'])->whereNumber('proceeds');
    Route::post('/capital/pending-sale-proceeds/{proceeds}/mark-available', [PendingSaleProceedsController::class, 'markAvailable'])->whereNumber('proceeds');
    Route::post('/capital/resolve', [CapitalResolutionController::class, 'resolve']);
    Route::get('/recommendations/{recommendation}/capital-resolution', [CapitalResolutionController::class, 'forRecommendation'])->whereNumber('recommendation');

    // Strategy Backtesting & Simulation (historical only; resumable time-budget engine).
    Route::get('/backtests/meta', [BacktestController::class, 'meta']);
    Route::get('/backtests', [BacktestController::class, 'index']);
    Route::post('/backtests', [BacktestController::class, 'store']);
    Route::get('/backtests/{id}', [BacktestController::class, 'show'])->whereNumber('id');
    Route::post('/backtests/{id}/continue', [BacktestController::class, 'continue'])->whereNumber('id');
    Route::put('/backtests/{id}', [BacktestController::class, 'update'])->whereNumber('id');
    Route::delete('/backtests/{id}', [BacktestController::class, 'destroy'])->whereNumber('id');
    Route::get('/backtests/{id}/timeline', [BacktestController::class, 'timeline'])->whereNumber('id');

    // Trading Artifact Registry (SD-034) — additive infrastructure; does not replace screeners/strategy APIs.
    Route::get('/artifacts', [ArtifactRegistryController::class, 'index']);
    Route::post('/artifacts/export', [ArtifactRegistryController::class, 'exportPackage']);
    Route::post('/artifacts/import', [ArtifactRegistryController::class, 'importPackage']);
    Route::post('/artifacts/validate', [ArtifactRegistryController::class, 'validatePackage']);
    Route::get('/artifacts/{type}', [ArtifactRegistryController::class, 'indexType'])
        ->where('type', 'indicator|screener|strategy');
    Route::post('/artifacts/{type}', [ArtifactRegistryController::class, 'store'])
        ->where('type', 'indicator|screener|strategy');
    Route::post('/artifacts/{type}/validate', [ArtifactRegistryController::class, 'validateArtifact'])
        ->where('type', 'indicator|screener|strategy');
    Route::get('/artifacts/{type}/{id}', [ArtifactRegistryController::class, 'show'])
        ->where('type', 'indicator|screener|strategy');
    Route::put('/artifacts/{type}/{id}', [ArtifactRegistryController::class, 'update'])
        ->where('type', 'indicator|screener|strategy');
    Route::post('/artifacts/{type}/{id}/export', [ArtifactRegistryController::class, 'exportOne'])
        ->where('type', 'indicator|screener|strategy');

    // Screener Registry — first-class reusable Screener artifacts (same definition_json; no run-engine changes).
    Route::get('/screener-registry/meta', [ScreenerRegistryController::class, 'meta']);
    Route::get('/screener-registry', [ScreenerRegistryController::class, 'index']);
    Route::post('/screener-registry', [ScreenerRegistryController::class, 'store']);
    Route::post('/screener-registry/validate', [ScreenerRegistryController::class, 'validateEnvelope']);
    Route::post('/screener-registry/import', [ScreenerRegistryController::class, 'import']);
    Route::post('/screener-registry/shared/{sourceId}/import', [ScreenerRegistryController::class, 'importShared'])
        ->whereNumber('sourceId');
    Route::get('/screener-registry/{id}', [ScreenerRegistryController::class, 'show'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::put('/screener-registry/{id}', [ScreenerRegistryController::class, 'update'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::get('/screener-registry/{id}/versions', [ScreenerRegistryController::class, 'versions'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::post('/screener-registry/{id}/export', [ScreenerRegistryController::class, 'export'])
        ->where('id', '[A-Za-z0-9_\\-]+');

    // Strategy Registry — reusable Strategy artifacts; multiple enabled strategies per portfolio.
    Route::get('/strategy-registry/meta', [StrategyRegistryController::class, 'meta']);
    Route::get('/strategy-registry/selection', [StrategyRegistryController::class, 'selection']);
    Route::get('/strategy-registry', [StrategyRegistryController::class, 'index']);
    Route::post('/strategy-registry', [StrategyRegistryController::class, 'store']);
    Route::post('/strategy-registry/validate', [StrategyRegistryController::class, 'validateEnvelope']);
    Route::post('/strategy-registry/import', [StrategyRegistryController::class, 'import']);
    Route::get('/strategy-registry/{id}', [StrategyRegistryController::class, 'show'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::put('/strategy-registry/{id}', [StrategyRegistryController::class, 'update'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::get('/strategy-registry/{id}/versions', [StrategyRegistryController::class, 'versions'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::post('/strategy-registry/{id}/export', [StrategyRegistryController::class, 'export'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::post('/strategy-registry/{id}/activate', [StrategyRegistryController::class, 'activate'])
        ->where('id', '[A-Za-z0-9_\\-]+');
    Route::post('/strategy-registry/{id}/archive', [StrategyRegistryController::class, 'archive'])
        ->where('id', '[A-Za-z0-9_\\-]+');

    Route::middleware('admin')->group(function () {
        Route::get('/indicators', [IndicatorRegistryController::class, 'index']);
        Route::get('/indicators/meta', [IndicatorRegistryController::class, 'meta']);
        Route::get('/indicators/{id}', [IndicatorRegistryController::class, 'show'])
            ->where('id', '[A-Za-z0-9_\\-]+');
    });
});
