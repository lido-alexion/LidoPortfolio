<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminOperationalAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalAlertController extends Controller
{
    public function index(AdminOperationalAlertService $alerts): JsonResponse
    {
        $alerts->syncActiveAlerts(false);

        return response()->json([
            'data' => $this->payload($alerts),
        ]);
    }

    public function acknowledge(Request $request, AdminOperationalAlertService $alerts): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        if (! $alerts->acknowledge($validated['key'])) {
            return response()->json(['message' => 'Alert not found or already resolved.'], 404);
        }

        return response()->json([
            'data' => $this->payload($alerts),
        ]);
    }

    public function acknowledgeAll(AdminOperationalAlertService $alerts): JsonResponse
    {
        $cleared = $alerts->acknowledgeAll();

        return response()->json([
            'data' => array_merge($this->payload($alerts), [
                'cleared_count' => $cleared,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AdminOperationalAlertService $alerts): array
    {
        $active = $alerts->getActiveAlertsForApi();
        $unacknowledged = array_values(array_filter(
            $active,
            static fn (array $row) => empty($row['acknowledged']),
        ));

        return [
            'active' => $active,
            'unacknowledged' => $unacknowledged,
            'unacknowledged_count' => count($unacknowledged),
            'admin_telegram_recipients' => $alerts->adminTelegramRecipientCount(),
        ];
    }
}
