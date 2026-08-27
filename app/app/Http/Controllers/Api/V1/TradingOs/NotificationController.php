<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Notification\NotificationEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Support\TradingOsPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationEngine $notification,
    ) {}

    public function notificationsIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $page = TradingOsPagination::resolve($request, TradingOsPagination::NOTIFICATIONS_DEFAULT);
        $paginator = $this->notification->paginateHistory($profile, $page['page'], $page['pageSize']);

        return ApiEnvelope::success($paginator->items(), TradingOsPagination::meta($paginator));
    }

    public function notificationsRetry(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $n = $this->notification->retry($profile, $id);
        if (! $n) {
            return ApiEnvelope::error('NOT_FOUND', 'Notification not found.', 404);
        }

        return ApiEnvelope::success($n);
    }
}
