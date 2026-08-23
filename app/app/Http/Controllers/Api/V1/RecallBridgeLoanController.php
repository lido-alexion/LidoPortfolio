<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\RecallBridgeLoan;
use App\Services\Lending\CapitalRecallPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecallBridgeLoanController extends Controller
{
    public function __construct(
        protected CapitalRecallPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $query = RecallBridgeLoan::query()
            ->where('profile_id', $profile->id)
            ->with(['lenderStrategy', 'borrowerStrategy', 'capitalRecall'])
            ->orderByDesc('id');

        if ($request->filled('capital_recall_id')) {
            $query->where('capital_recall_id', (int) $request->input('capital_recall_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('strategy_id')) {
            $sid = (int) $request->input('strategy_id');
            $query->where(function ($q) use ($sid) {
                $q->where('lender_strategy_id', $sid)->orWhere('borrower_strategy_id', $sid);
            });
        }
        if ($request->filled('borrower_strategy_id')) {
            $query->where('borrower_strategy_id', (int) $request->input('borrower_strategy_id'));
        }
        if ($request->filled('lender_strategy_id')) {
            $query->where('lender_strategy_id', (int) $request->input('lender_strategy_id'));
        }

        $limit = min(100, max(1, (int) $request->input('limit', 50)));
        $rows = $query->limit($limit)->get();

        return ApiEnvelope::success(
            $rows->map(fn (RecallBridgeLoan $b) => $this->presenter->bridgeLoan($b, false))->all(),
            ['count' => $rows->count()]
        );
    }

    public function show(int $bridgeLoan): JsonResponse
    {
        $profile = \activePortfolio();
        $row = RecallBridgeLoan::query()
            ->where('profile_id', $profile->id)
            ->where('id', $bridgeLoan)
            ->first();
        if ($row === null) {
            return ApiEnvelope::error('NOT_FOUND', 'Recall Bridge Loan not found.', 404);
        }

        return ApiEnvelope::success($this->presenter->bridgeLoan($row, true));
    }

    /**
     * Bridge loans are created only by the automated recall-resolution workflow.
     */
    public function store(): JsonResponse
    {
        return ApiEnvelope::error(
            'FORBIDDEN',
            'Recall Bridge Loans cannot be created manually. They are created by the recall settlement workflow only.',
            405
        );
    }
}
