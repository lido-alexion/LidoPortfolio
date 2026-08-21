<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\CapitalLoan;
use App\Models\CapitalRequest;
use App\Models\TradingStrategy;
use App\Services\Lending\CapitalRequestApprovalService;
use App\Services\Lending\CapitalRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapitalLendingController extends Controller
{
    public function __construct(
        protected CapitalRequestService $requests,
        protected CapitalRequestApprovalService $approval,
    ) {}

    public function lenders(int $capitalRequest): JsonResponse
    {
        $profile = \activePortfolio();
        $request = $this->findRequest($profile->id, $capitalRequest);

        return ApiEnvelope::success([
            'capital_request_id' => $request->id,
            'amount' => (float) $request->amount,
            'lenders' => $this->requests->eligibleLenders($request),
        ]);
    }

    public function approve(Request $http, int $capitalRequest): JsonResponse
    {
        $profile = \activePortfolio();
        $request = $this->findRequest($profile->id, $capitalRequest);
        $validated = $http->validate([
            'lender_strategy_id' => 'required|integer|min:1',
        ]);

        $lender = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('id', (int) $validated['lender_strategy_id'])
            ->first();
        if ($lender === null) {
            return ApiEnvelope::error('LENDER_NOT_FOUND', 'Unknown lender strategy for this portfolio.', 422);
        }

        $loan = $this->approval->approve($request, $lender, $http->user());

        return ApiEnvelope::success($this->loanPayload($loan->fresh(), $request->fresh()));
    }

    public function reject(Request $http, int $capitalRequest): JsonResponse
    {
        $profile = \activePortfolio();
        $request = $this->findRequest($profile->id, $capitalRequest);
        $updated = $this->approval->reject($request, $http->user());

        return ApiEnvelope::success([
            'id' => $updated->id,
            'status' => $updated->status,
            'approved_by' => $updated->approved_by,
            'loan_id' => $updated->loan?->id,
        ]);
    }

    protected function findRequest(int $profileId, int $id): CapitalRequest
    {
        return CapitalRequest::query()
            ->where('profile_id', $profileId)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function loanPayload(CapitalLoan $loan, CapitalRequest $request): array
    {
        return [
            'capital_request' => [
                'id' => $request->id,
                'status' => $request->status,
                'lender_strategy_id' => $request->lender_strategy_id,
                'approved_at' => $request->approved_at?->toIso8601String(),
                'approved_by' => $request->approved_by,
            ],
            'loan' => [
                'id' => $loan->id,
                'principal' => (float) $loan->principal,
                'outstanding' => (float) $loan->outstanding,
                'borrower_strategy_id' => $loan->borrower_strategy_id,
                'lender_strategy_id' => $loan->lender_strategy_id,
                'committed_at' => $loan->committed_at?->toIso8601String(),
                'status' => $loan->status,
            ],
        ];
    }
}
