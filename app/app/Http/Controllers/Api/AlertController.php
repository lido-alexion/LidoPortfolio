<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\AlertExpirationService;
use App\Services\StoplossService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AlertController extends Controller
{
    public function __construct(
        protected StoplossService $stoploss,
        protected AlertExpirationService $expiration,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->stoploss->getActiveAlertsForUser($request->user())]);
    }

    public function expireAll(Request $request): JsonResponse
    {
        $count = $this->expiration->expireAllForUser($request->user());

        return response()->json([
            'message' => $count > 0 ? "Cleared {$count} alert(s)." : 'No active alerts to clear.',
            'expired_count' => $count,
        ]);
    }

    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        if (! $this->expiration->acknowledgeForUser($request->user(), $alert)) {
            return response()->json([
                'message' => 'Alert not found or you no longer hold this stock.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'Alert acknowledged.',
            'data' => $alert->fresh()->load('stock'),
        ]);
    }
}
