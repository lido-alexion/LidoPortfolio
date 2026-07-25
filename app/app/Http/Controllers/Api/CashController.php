<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashController extends Controller
{
    public function __construct(
        protected CashManagementService $cash,
    ) {}

    public function summary(): JsonResponse
    {
        $profile = \activePortfolio();

        return response()->json([
            'data' => $this->cash->summary($profile),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $limit = min(100, max(1, (int) $request->input('limit', 50)));

        $entries = array_map(fn ($e) => [
            'id' => $e->id,
            'entry_type' => $e->entry_type,
            'amount' => (float) $e->amount,
            'balance_after' => (float) $e->balance_after,
            'reason' => $e->reason,
            'transaction_id' => $e->transaction_id,
            'recommendation_id' => $e->recommendation_id,
            'created_at' => optional($e->created_at)?->toIso8601String(),
        ], $this->cash->recentEntries($profile, $limit));

        return response()->json(['data' => $entries]);
    }

    public function deposit(Request $request): JsonResponse
    {
        return $this->mutate($request, 'deposit');
    }

    public function withdraw(Request $request): JsonResponse
    {
        return $this->mutate($request, 'withdraw');
    }

    public function adjust(Request $request): JsonResponse
    {
        return $this->mutate($request, 'adjust');
    }

    protected function mutate(Request $request, string $op): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $entry = match ($op) {
                'deposit' => $this->cash->deposit($profile, (float) $validated['amount'], $validated['reason'], $request->user()),
                'withdraw' => $this->cash->withdraw($profile, (float) $validated['amount'], $validated['reason'], $request->user()),
                default => $this->cash->adjust($profile, (float) $validated['amount'], $validated['reason'], $request->user()),
            };
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json([
            'data' => [
                'entry' => [
                    'id' => $entry->id,
                    'entry_type' => $entry->entry_type,
                    'amount' => (float) $entry->amount,
                    'balance_after' => (float) $entry->balance_after,
                    'reason' => $entry->reason,
                ],
                'summary' => $this->cash->summary($profile),
            ],
        ], 201);
    }
}
