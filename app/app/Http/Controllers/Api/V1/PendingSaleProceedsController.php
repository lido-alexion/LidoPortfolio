<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\PendingSaleProceeds;
use App\Services\Lending\CapitalRecallPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PendingSaleProceedsController extends Controller
{
    public function __construct(
        protected CapitalRecallPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $query = PendingSaleProceeds::query()
            ->where('profile_id', $profile->id)
            ->with(['transaction', 'strategy'])
            ->orderByDesc('id');

        if ($request->filled('strategy_id')) {
            $query->where('strategy_id', (int) $request->input('strategy_id'));
        }
        if ($request->filled('capital_recall_id')) {
            $query->where('capital_recall_id', (int) $request->input('capital_recall_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('obligation_type')) {
            $query->where('obligation_type', (string) $request->input('obligation_type'));
        }

        $limit = min(100, max(1, (int) $request->input('limit', 50)));
        $rows = $query->limit($limit)->get();

        return ApiEnvelope::success(
            $rows->map(fn (PendingSaleProceeds $p) => $this->presenter->pendingProceeds($p))->all(),
            [
                'count' => $rows->count(),
                'terminology' => 'Proceeds from Stock Sale',
            ]
        );
    }

    public function show(int $proceeds): JsonResponse
    {
        $profile = \activePortfolio();
        $row = PendingSaleProceeds::query()
            ->where('profile_id', $profile->id)
            ->where('id', $proceeds)
            ->first();
        if ($row === null) {
            return ApiEnvelope::error('NOT_FOUND', 'Pending sale proceeds not found.', 404);
        }

        return ApiEnvelope::success($this->presenter->pendingProceeds($row));
    }

    /**
     * Availability is controlled by SaleProceedsAvailabilityService / settlement job only.
     */
    public function markAvailable(): JsonResponse
    {
        return ApiEnvelope::error(
            'FORBIDDEN',
            'Proceeds from Stock Sale cannot be marked available manually. Settlement processing controls availability.',
            405
        );
    }
}
