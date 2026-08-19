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

    public function summary(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $includeReservations = filter_var(
            $request->input('include_reservations', false),
            FILTER_VALIDATE_BOOLEAN
        );

        $summary = $this->cash->summary($profile, $includeReservations);
        $capital = app(\App\Services\Strategy\PortfolioCapitalAccountingService::class)->snapshot($profile);
        $summary['available_physical_cash'] = $capital['physical_cash']['available_physical_cash'];
        $summary['required_cash_reserve'] = $capital['od19']['required_cash_reserve'];
        $summary['portfolio_cash_reserve_pct'] = $capital['od19']['portfolio_cash_reserve_pct'];
        $summary['reserve_shortfall'] = $capital['od19']['reserve_shortfall'];
        $summary['reserve_shortfall_exists'] = $capital['od19']['reserve_shortfall_exists'];
        $summary['unallocated_cash'] = $capital['od20']['unallocated_cash'];
        $summary['investable_capital'] = $capital['investable_capital'];
        $summary['strategies'] = $capital['strategies'];
        $summary['capital'] = $capital;

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function reservations(): JsonResponse
    {
        $profile = \activePortfolio();

        return response()->json([
            'data' => $this->cash->reservationDetails($profile),
            'meta' => [
                'reserved_cash' => $this->cash->reservedCash($profile),
            ],
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
            'entry_date' => optional($e->entry_date)?->toDateString(),
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
            'reason' => 'nullable|string|max:500',
            'remarks' => 'nullable|string|max:500',
            'transaction_date' => 'nullable|date|before_or_equal:today',
            'entry_date' => 'nullable|date|before_or_equal:today',
        ]);

        $remarks = trim((string) ($validated['remarks'] ?? $validated['reason'] ?? ''));
        $entryDate = $validated['transaction_date'] ?? $validated['entry_date'] ?? null;
        $amount = (float) $validated['amount'];

        // Whole-rupee cash movements from the Cash UI.
        if ($op !== 'adjust' && abs($amount - round($amount)) > 0.0001) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be a whole number of rupees.'],
            ]);
        }
        if ($op === 'adjust' && abs($amount - round($amount)) > 0.0001) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be a whole number of rupees.'],
            ]);
        }
        $amount = round($amount);

        try {
            $entry = match ($op) {
                'deposit' => $this->cash->deposit($profile, $amount, $remarks !== '' ? $remarks : null, $request->user(), $entryDate),
                'withdraw' => $this->cash->withdraw($profile, $amount, $remarks !== '' ? $remarks : null, $request->user(), $entryDate),
                default => $this->cash->adjust($profile, $amount, $remarks !== '' ? $remarks : null, $request->user(), $entryDate),
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
                    'entry_date' => optional($entry->entry_date)?->toDateString(),
                ],
                'summary' => $this->cash->summary($profile),
            ],
        ], 201);
    }
}
