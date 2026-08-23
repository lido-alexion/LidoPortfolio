<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\TradingStrategy;
use App\Services\Lending\CapitalRecallPresenter;
use App\Services\Lending\RecallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CapitalRecallController extends Controller
{
    public function __construct(
        protected RecallService $recalls,
        protected CapitalRecallPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $query = CapitalRecall::query()
            ->where('profile_id', $profile->id)
            ->with(['loan', 'lenderStrategy', 'borrowerStrategy'])
            ->orderByDesc('id');

        if ($request->filled('loan_id')) {
            $query->where('loan_id', (int) $request->input('loan_id'));
        }
        if ($request->filled('state')) {
            $query->where('state', (string) $request->input('state'));
        }
        if ($request->filled('lender_strategy_id')) {
            $query->where('lender_strategy_id', (int) $request->input('lender_strategy_id'));
        }
        if ($request->filled('borrower_strategy_id')) {
            $query->where('borrower_strategy_id', (int) $request->input('borrower_strategy_id'));
        }
        if ($request->filled('strategy_id')) {
            $sid = (int) $request->input('strategy_id');
            $query->where(function ($q) use ($sid) {
                $q->where('lender_strategy_id', $sid)->orWhere('borrower_strategy_id', $sid);
            });
        }
        if ($request->boolean('active')) {
            $query->whereIn('state', CapitalRecall::ACTIVE_STATES);
        }
        if ($request->boolean('completed')) {
            $query->where('state', CapitalRecall::STATE_COMPLETED);
        }
        if ($request->filled('from')) {
            $query->where('requested_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('requested_at', '<=', $request->input('to'));
        }

        $limit = min(100, max(1, (int) $request->input('limit', 50)));
        $rows = $query->limit($limit)->get();

        return ApiEnvelope::success(
            $rows->map(fn (CapitalRecall $r) => $this->presenter->recall($r, false))->all(),
            ['count' => $rows->count()]
        );
    }

    public function show(int $recall): JsonResponse
    {
        $profile = \activePortfolio();
        $row = CapitalRecall::query()
            ->where('profile_id', $profile->id)
            ->where('id', $recall)
            ->first();
        if ($row === null) {
            return ApiEnvelope::error('NOT_FOUND', 'Recall not found.', 404);
        }

        return ApiEnvelope::success($this->presenter->recall($row, true));
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        try {
            $validated = $request->validate([
                'loan_id' => 'required|integer|min:1',
                'kind' => 'required|string|in:full,partial',
                'amount' => 'nullable|numeric|min:0',
                'lender_strategy_id' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $loan = CapitalLoan::query()
            ->where('profile_id', $profile->id)
            ->where('id', (int) $validated['loan_id'])
            ->first();
        if ($loan === null) {
            return ApiEnvelope::error('LOAN_NOT_FOUND', 'Unknown loan for this portfolio.', 422);
        }

        // Authorization: acting as lender only (borrower cannot initiate).
        if (isset($validated['lender_strategy_id'])) {
            $actingLender = (int) $validated['lender_strategy_id'];
            if ($actingLender !== (int) $loan->lender_strategy_id) {
                return ApiEnvelope::error(
                    'UNAUTHORIZED_STRATEGY',
                    'Only the loan lender strategy may request a recall.',
                    403
                );
            }
            $strategy = TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('id', $actingLender)
                ->first();
            if ($strategy === null) {
                return ApiEnvelope::error('UNAUTHORIZED_STRATEGY', 'Unknown lender strategy.', 403);
            }
        }

        $kind = strtolower((string) $validated['kind']);
        if ($kind === 'partial' && ! isset($validated['amount'])) {
            return ApiEnvelope::error('VALIDATION_ERROR', 'Partial recall requires amount.', 422);
        }
        try {
            $processed = $this->recalls->requestAndProcess(
                $profile,
                $loan,
                $kind,
                isset($validated['amount']) ? (float) $validated['amount'] : 0.0,
            );
            $recall = $processed['recall'];
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return ApiEnvelope::error('VALIDATION_ERROR', (string) $msg, 422);
        } catch (\InvalidArgumentException $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (\LogicException $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        return ApiEnvelope::success($this->presenter->recall($recall->fresh(), true), [], 201);
    }
}
