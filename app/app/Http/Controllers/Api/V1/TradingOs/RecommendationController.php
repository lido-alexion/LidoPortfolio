<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Notification\NotificationEngine;
use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\TradingRecommendation;
use App\Services\CashManagementService;
use App\Support\TradingOsConfig;
use App\Support\TradingOsPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RecommendationController extends Controller
{
    public function __construct(
        protected RecommendationEngine $recommendation,
        protected NotificationEngine $notification,
        protected CashManagementService $cash,
    ) {}

    public function recommendationsGenerate(): JsonResponse
    {
        $profile = \activePortfolio();
        $result = $this->recommendation->generate($profile);

        if (TradingOsConfig::notificationNotifyOnGenerate()) {
            $this->notification->notifyRecommendations($profile, $result['recommendations']);
        }

        return ApiEnvelope::success([
            'batch_id' => $result['batch_id'],
            'recommendations' => array_map(fn ($r) => TradingOsPresenter::recommendation($r), $result['recommendations']),
        ], [], 201);
    }

    public function recommendationsIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $statusParam = $request->query('status');
        $statuses = null;
        if (is_string($statusParam) && trim($statusParam) !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $statusParam))));
        } elseif ($request->boolean('open', true) && ! $request->has('status') && ! $request->boolean('all')) {
            $statuses = TradingRecommendation::OPEN_LIST_STATUSES;
        }

        $page = TradingOsPagination::resolve($request, TradingOsPagination::RECOMMENDATIONS_DEFAULT);
        $paginator = $this->recommendation->paginateForProfile(
            $profile,
            $statuses,
            $page['page'],
            $page['pageSize'],
        );

        return ApiEnvelope::success(
            array_map(fn ($r) => TradingOsPresenter::recommendation($r), $paginator->items()),
            TradingOsPagination::meta($paginator),
        );
    }

    public function recommendationsPendingExecution(): JsonResponse
    {
        $profile = \activePortfolio();
        $items = $this->recommendation->listPendingExecution($profile);
        $cash = $this->cash->summary($profile);

        return ApiEnvelope::success(
            array_map(fn ($r) => TradingOsPresenter::recommendation($r, true), $items),
            ['cash' => $cash],
        );
    }

    public function recommendationsShow(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        return ApiEnvelope::success(TradingOsPresenter::recommendation($rec, true));
    }

    public function recommendationsReview(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'decision' => 'required|in:approved,accepted,rejected,deferred,APPROVED,ACCEPTED,REJECTED,DEFERRED',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->recordReview(
                $profile,
                $request->user(),
                $rec,
                strtolower($validated['decision']),
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success(TradingOsPresenter::recommendation($updated, true));
    }

    public function recommendationsReopen(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->reopenForReview(
                $profile,
                $request->user(),
                $rec,
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success(TradingOsPresenter::recommendation($updated, true));
    }

    public function recommendationsCancelExecution(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|in:'.implode(',', TradingRecommendation::CANCELLATION_REASONS),
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->cancelExecution(
                $profile,
                $request->user(),
                $rec,
                $validated['reason'] ?? 'other',
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success(TradingOsPresenter::recommendation($updated, true));
    }

    public function recommendationsExpire(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->markExpired(
                $profile,
                $request->user(),
                $rec,
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            return TradingOsHttp::validationError($e);
        }

        return ApiEnvelope::success(TradingOsPresenter::recommendation($updated, true));
    }

    public function recommendationsReviewHistory(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $items = $this->recommendation->reviewHistory($rec);

        return ApiEnvelope::success(array_map(fn ($r) => TradingOsPresenter::reviewHistoryItem($r), $items));
    }
}
