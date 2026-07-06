<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AlertPolicyController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExplorerAnalyticsController;
use App\Http\Controllers\Api\FrontendLogController;
use App\Http\Controllers\Api\HoldingController;
use App\Http\Controllers\Api\InviteAcceptController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\PortfolioHistoryController;
use App\Http\Controllers\Api\OperationalAlertController;
use App\Http\Controllers\Api\PasswordResetAcceptController;
use App\Http\Controllers\Api\PasswordResetLinkController;
use App\Http\Controllers\Api\KnowledgeBoardNoteController;
use App\Http\Controllers\Api\KnowledgeBoardTagController;
use App\Http\Controllers\Api\PatternScanController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockPriceController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\SyncLogController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\CorporateActionController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UniversePriceSyncController;
use App\Http\Controllers\Api\UserInviteController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WatchlistController;
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
    Route::apiResource('transactions', TransactionController::class);

    Route::get('/corporate-actions', [CorporateActionController::class, 'index']);
    Route::post('/corporate-actions/preview', [CorporateActionController::class, 'preview']);
    Route::post('/corporate-actions', [CorporateActionController::class, 'store']);

    Route::get('/holdings', [HoldingController::class, 'index']);
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::post('/watchlist', [WatchlistController::class, 'store']);
    Route::put('/watchlist/{watchlistItem}', [WatchlistController::class, 'update']);
    Route::delete('/watchlist/{watchlistItem}', [WatchlistController::class, 'destroy']);
    Route::get('/stocks/{stock}/prices', [StockPriceController::class, 'index']);
    Route::get('/stocks/{stock}/market-prices', [StockPriceController::class, 'market']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/patterns/scan', [PatternScanController::class, 'index']);

    Route::prefix('calendar')->group(function () {
        Route::get('/events', [CalendarEventController::class, 'index']);
        Route::get('/occurrences', [CalendarEventController::class, 'occurrences']);
        Route::get('/upcoming', [CalendarEventController::class, 'upcoming']);
        Route::post('/events', [CalendarEventController::class, 'store']);
        Route::put('/events/{calendarEvent}', [CalendarEventController::class, 'update']);
        Route::delete('/events/{calendarEvent}', [CalendarEventController::class, 'destroy']);
    });

    Route::prefix('knowledge-board')->group(function () {
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
    Route::get('/analytics/portfolio', [AnalyticsController::class, 'portfolio']);
    Route::get('/analytics/stocks/{stock}', [AnalyticsController::class, 'stock']);
    Route::post('/analytics/explore', [ExplorerAnalyticsController::class, 'analyze'])
        ->middleware('throttle:analytics-explore');

    Route::get('/alerts', [AlertController::class, 'index']);
    Route::post('/alerts/expire-all', [AlertController::class, 'expireAll']);
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);

    Route::get('/alert-policies/meta', [AlertPolicyController::class, 'meta']);
    Route::post('/alert-policies/evaluate', [AlertPolicyController::class, 'evaluate']);
    Route::apiResource('alert-policies', AlertPolicyController::class);

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/test-telegram', [SettingsController::class, 'testTelegram']);

    Route::middleware('admin')->group(function () {
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
    });
});
