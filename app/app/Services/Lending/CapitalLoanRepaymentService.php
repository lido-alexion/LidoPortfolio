<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WS4 Step 7 — explicit repayment of an outstanding CapitalLoan.
 *
 * Accounting-only: creates CapitalLoanReturn and reduces outstanding.
 * Does not move stock, change allocation_pct, or post cash-ledger rows
 * (no loan-return entry type exists on the single physical cash pool).
 * Does not implement recall.
 */
final class CapitalLoanRepaymentService
{
    public function repay(CapitalLoan $loan, float $amount): CapitalLoanReturn
    {
        return DB::transaction(function () use ($loan, $amount) {
            /** @var CapitalLoan $locked */
            $locked = CapitalLoan::query()
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = round($amount, 4);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Repayment amount must be greater than 0.'],
                ]);
            }

            $outstanding = round((float) $locked->outstanding, 4);
            if ($locked->status === CapitalLoan::STATUS_RETURNED || $outstanding <= 0) {
                throw ValidationException::withMessages([
                    'loan' => ['This loan has already been fully returned.'],
                ]);
            }

            if ($amount > $outstanding + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Repayment cannot exceed outstanding (need '.$amount.', outstanding '.$outstanding.').',
                    ],
                ]);
            }

            $newOutstanding = round(max(0.0, $outstanding - $amount), 4);
            if ($newOutstanding <= 0.0001) {
                $newOutstanding = 0.0;
            }

            $locked->forceFill([
                'outstanding' => $newOutstanding,
                'status' => $newOutstanding <= 0.0
                    ? CapitalLoan::STATUS_RETURNED
                    : CapitalLoan::STATUS_PARTIALLY_RETURNED,
            ])->save();

            return CapitalLoanReturn::query()->create([
                'loan_id' => $locked->id,
                'capital_request_id' => $locked->capital_request_id,
                'amount' => $amount,
                'returned_at' => now(),
                'created_at' => now(),
            ]);
        });
    }
}
