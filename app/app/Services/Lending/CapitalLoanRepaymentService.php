<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
use App\Models\PortfolioProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WS4 Step 7 — explicit repayment of an outstanding CapitalLoan.
 *
 * Creates CapitalLoanReturn and reduces outstanding. Does not move stock
 * or change allocation_pct. V4-SPEC-004 posts signed LOAN cash rows that
 * net to zero on the single physical pool unless $postCash is false
 * (caller already posted RECALL/BRIDGE for the same money movement).
 */
final class CapitalLoanRepaymentService
{
    public function __construct(
        protected SpecialCashMovementService $specialCash,
    ) {}

    public function repay(CapitalLoan $loan, float $amount, bool $postCash = true): CapitalLoanReturn
    {
        return DB::transaction(function () use ($loan, $amount, $postCash) {
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

            $row = CapitalLoanReturn::query()->create([
                'loan_id' => $locked->id,
                'capital_request_id' => $locked->capital_request_id,
                'amount' => $amount,
                'returned_at' => now(),
                'created_at' => now(),
            ]);

            if ($postCash) {
                $profile = PortfolioProfile::query()->findOrFail($locked->profile_id);
                $this->specialCash->postLoanRepayment($profile, $locked, $row);
            }

            return $row;
        });
    }
}
