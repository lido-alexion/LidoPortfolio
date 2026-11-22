<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashLedgerEntry;
use App\Models\CashAccount;
use App\Services\CashManagementService;
use App\Models\KiteFundsSnapshot;
use App\Models\KiteFundsReconciliation;
use App\Services\Lending\CapitalRecallPresenter;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

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
        $capital = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
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

    public function kiteFunds(): JsonResponse
    {
        $profile = \activePortfolio();
        $snapshots = KiteFundsSnapshot::query()->where('profile_id', $profile->id)->orderByDesc('captured_at')->limit(48)->get();
        $reconciliations = KiteFundsReconciliation::query()->where('profile_id', $profile->id)->orderByDesc('trading_date')->limit(30)->get();
        return response()->json(['data' => [
            'automatic_adjustments_enabled' => (bool) config('broker.kite_funds.auto_adjustments_enabled', false),
            'payout_semantics_verified' => (bool) config('broker.kite_funds.payout_semantics_verified', false),
            'snapshots' => $snapshots->map(fn ($row) => [
                'id' => $row->id, 'account' => $row->broker_account_id, 'trading_date' => $row->trading_date->toDateString(),
                'captured_at' => $row->captured_at->toIso8601String(), 'available_balance' => $row->available_balance,
                'opening_balance' => $row->opening_balance, 'intraday_payin' => $row->intraday_payin,
                'utilised_payout' => $row->utilised_payout, 'freshness' => $row->freshness, 'source' => $row->capture_source,
            ]),
            'reconciliations' => $reconciliations->map(fn ($row) => [
                'id' => $row->id, 'trading_date' => $row->trading_date->toDateString(), 'status' => $row->status,
                'snapshot_net_flow' => $row->snapshot_net_flow, 'manual_net_flow' => $row->manual_net_flow,
                'evaluated_at' => $row->evaluated_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function confirmKiteFundsAdjustment(Request $request, int $reconciliation): JsonResponse
    {
        if (! config('broker.kite_funds.auto_adjustments_enabled', false)
            || ! config('broker.kite_funds.payout_semantics_verified', false)) {
            return response()->json(['message' => 'Confirmation is disabled until Console payout evidence is verified.'], 409);
        }
        $profile = \activePortfolio();
        return DB::transaction(function () use ($profile, $request, $reconciliation) {
            $this->cash->ensureAccount($profile);
            CashAccount::query()->where('profile_id', $profile->id)->lockForUpdate()->firstOrFail();
            $row = KiteFundsReconciliation::query()->where('profile_id', $profile->id)->lockForUpdate()->findOrFail($reconciliation);
            if ($row->status !== 'adjustment_proposed' || $row->cash_ledger_entry_id) {
                return response()->json(['message' => 'This reconciliation is no longer awaiting confirmation.'], 409);
            }
            $manual = DB::table('portfolio_cash_ledger_entries')->where('profile_id', $profile->id)->whereDate('entry_date', $row->trading_date)
                ->whereIn('entry_type', ['deposit', 'withdrawal'])->whereNull('reversal_of_entry_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('portfolio_cash_ledger_entries as reversal')->whereColumn('reversal.reversal_of_entry_id', 'portfolio_cash_ledger_entries.id'))
                ->exists();
            if ($manual || $row->snapshot_net_flow === null || abs((float) $row->snapshot_net_flow) < 0.0001) {
                return response()->json(['message' => 'Manual transfers exist or net flow is zero; review manually.'], 409);
            }
            $entry = $this->cash->adjust($profile, (string) $row->snapshot_net_flow,
                'Kite net bank-transfer adjustment for '.$row->trading_date->toDateString().' (pay-in less payout); aggregate broker evidence, not an individual bank transfer.',
                $request->user(), $row->trading_date->toDateString());
            $entry->forceFill(['entry_classification' => 'kite_net_adjustment'])->save();
            $row->forceFill(['status' => 'adjustment_confirmed', 'cash_ledger_entry_id' => $entry->id, 'evaluated_at' => now()])->save();
            return response()->json(['data' => ['id' => $entry->id, 'status' => $row->status]], 201);
        });
    }

    public function suppressKiteFundsAdjustment(int $reconciliation): JsonResponse
    {
        $row = KiteFundsReconciliation::query()->where('profile_id', \activePortfolio()->id)->findOrFail($reconciliation);
        if ($row->status !== 'adjustment_proposed' || $row->cash_ledger_entry_id) {
            return response()->json(['message' => 'Only an unconfirmed proposal can be dismissed.'], 409);
        }
        $row->forceFill(['status' => 'adjustment_suppressed', 'suppression_reason' => 'user_dismissed', 'evaluated_at' => now()])->save();
        return response()->json(['data' => ['status' => $row->status]]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $limit = min(100, max(1, (int) $request->input('limit', 50)));

        $entries = array_map(function ($e) {
            $reason = $e->reason;
            $kind = app(CapitalRecallPresenter::class)->cashMovementKind($reason, $e->entry_type);

            return [
                'id' => $e->id,
                'entry_type' => $e->entry_type,
                'entry_classification' => $e->entry_classification,
                'amount' => (float) $e->amount,
                'balance_after' => (float) $e->balance_after,
                'reason' => $reason,
                'movement_kind' => $kind,
                'movement_label' => match ($kind) {
                    'proceeds_from_stock_sale' => 'Proceeds from Stock Sale',
                    'recall_bridge_loan' => 'Recall Bridge Loan',
                    'recall' => 'Recall',
                    'normal_loan_repayment' => 'Normal loan repayment',
                    'normal_loan' => 'Normal loan',
                    default => null,
                },
                'entry_date' => optional($e->entry_date)?->toDateString(),
                'transaction_id' => $e->transaction_id,
                'recommendation_id' => $e->recommendation_id,
                'created_at' => optional($e->created_at)?->toIso8601String(),
            ];
        }, $this->cash->recentEntries($profile, $limit));

        return response()->json(['data' => $entries]);
    }

    public function statement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date|before_or_equal:today',
            'to' => 'nullable|date|before_or_equal:today|after_or_equal:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable|string|in:asc,desc',
            'entry_type' => 'nullable|string|in:'.implode(',', CashLedgerEntry::TYPES),
        ]);

        return response()->json([
            'data' => $this->cash->statement(
                \activePortfolio(),
                $validated['from'] ?? null,
                $validated['to'] ?? null,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 50),
                $validated['entry_type'] ?? null,
                null,
                $validated['order'] ?? 'asc',
            ),
        ]);
    }

    public function asOf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date|before_or_equal:today',
        ]);

        $cash = $this->cash->cashAsOf(\activePortfolio(), $validated['date']);

        return response()->json(['data' => [
            'requested_date' => $validated['date'],
            'cash_balance' => $cash['balance'],
            'reservations_included' => false,
            'complete' => $cash['complete'],
            'complete_from' => $cash['complete_from'],
            'incomplete_reason' => $cash['reason'],
        ]]);
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

    public function reverse(Request $request, int $entry): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $reversal = $this->cash->reverseCashMovement(
            \activePortfolio(),
            $entry,
            $validated['reason'],
            $request->user(),
            $validated['entry_date'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $reversal->id,
            'reversal_of_entry_id' => $reversal->reversal_of_entry_id,
            'amount' => (float) $reversal->amount,
            'reason' => $reversal->reason,
        ]], 201);
    }

    public function updateEntry(Request $request, int $entry): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'regex:/^-?\d{1,14}(?:\.\d{1,4})?$/'],
            'reason' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'transaction_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $updated = $this->cash->amendCashMovement(
            \activePortfolio(), $entry, (string) $validated['amount'],
            trim((string) ($validated['remarks'] ?? $validated['reason'] ?? '')),
            $request->user(), $validated['entry_date'] ?? $validated['transaction_date'] ?? null,
        );

        return response()->json(['data' => ['id' => $updated->id, 'entry_type' => $updated->entry_type, 'amount' => $updated->amount, 'balance_after' => $updated->balance_after]], 201);
    }

    public function deleteEntry(Request $request, int $entry): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reversal = $this->cash->reverseCashMovement(\activePortfolio(), $entry,
            trim((string) ($validated['reason'] ?? 'Deleted from cash management')), $request->user());
        return response()->json(['data' => ['id' => $reversal->id, 'reversal_of_entry_id' => $reversal->reversal_of_entry_id]], 201);
    }

    protected function mutate(Request $request, string $op): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'regex:/^-?\d{1,14}(?:\.\d{1,4})?$/'],
            'reason' => $op === 'adjust' ? 'required_without:remarks|string|max:500' : 'nullable|string|max:500',
            'remarks' => 'nullable|string|max:500',
            'transaction_date' => 'nullable|date|before_or_equal:today',
            'entry_date' => 'nullable|date|before_or_equal:today',
        ]);

        $remarks = trim((string) ($validated['remarks'] ?? $validated['reason'] ?? ''));
        $entryDate = $validated['transaction_date'] ?? $validated['entry_date'] ?? null;
        $amount = (string) $validated['amount'];

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
