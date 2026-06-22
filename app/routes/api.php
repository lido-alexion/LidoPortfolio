<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExplorerAnalyticsController;
use App\Http\Controllers\Api\FrontendLogController;
use App\Http\Controllers\Api\HoldingController;
use App\Http\Controllers\Api\PortfolioHistoryController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockPriceController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\SyncLogController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

// Guest-safe session probe — must not require auth:sanctum (returns { user: null } when logged out).
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/auth/csrf-token', [AuthController::class, 'csrfToken']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logs/frontend', [FrontendLogController::class, 'store']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/sessions', [AuthController::class, 'sessions']);
    Route::post('/auth/sessions/logout-others', [AuthController::class, 'logoutOtherSessions']);
    Route::delete('/auth/sessions/{sessionId}', [AuthController::class, 'logoutSession']);

    Route::get('/stocks/search', [StockController::class, 'search'])
        ->middleware('throttle:stock-search');
    Route::post('/stocks/validate', [StockController::class, 'validateSymbol'])
        ->middleware('throttle:stock-validate');
    Route::apiResource('stocks', StockController::class)->except(['destroy']);
    Route::apiResource('transactions', TransactionController::class);

    Route::get('/holdings', [HoldingController::class, 'index']);
    Route::get('/stocks/{stock}/prices', [StockPriceController::class, 'index']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/portfolio/rebuild-history', [PortfolioHistoryController::class, 'rebuild']);
    Route::get('/analytics/portfolio', [AnalyticsController::class, 'portfolio']);
    Route::get('/analytics/stocks/{stock}', [AnalyticsController::class, 'stock']);
    Route::post('/analytics/explore', [ExplorerAnalyticsController::class, 'analyze'])
        ->middleware('throttle:analytics-explore');

    Route::get('/alerts', [AlertController::class, 'index']);
    Route::post('/alerts/expire-all', [AlertController::class, 'expireAll']);
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);

    Route::post('/sync/daily', [SyncController::class, 'daily']);
    Route::post('/sync/backfill/{stock}', [SyncController::class, 'backfill']);

    Route::get('/sync-logs', [SyncLogController::class, 'index']);
    Route::get('/sync-logs/runs', [SyncLogController::class, 'runs']);
    Route::get('/sync-logs/export', [SyncLogController::class, 'export']);
});
