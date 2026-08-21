<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRequest;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Support\CommittedLoanAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit approve/reject. Creates one CapitalLoan on success (accounting claim only).
 */
final class CapitalRequestApprovalService
{
    public function __construct(
        protected LenderEligibilityService $eligibility,
        protected RecommendationLendingCoordinator $lending,
    ) {}

    public function approve(CapitalRequest $request, TradingStrategy $lender, User $approver): CapitalLoan
    {
        $result = DB::transaction(function () use ($request, $lender, $approver) {
            /** @var CapitalRequest $locked */
            $locked = CapitalRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->loan()->exists()) {
                throw ValidationException::withMessages([
                    'capital_request' => ['This capital request already has a committed loan.'],
                ]);
            }

            if (! $locked->isApprovable()) {
                throw ValidationException::withMessages([
                    'capital_request' => ['This capital request cannot be approved in its current status.'],
                ]);
            }

            if ((int) $lender->profile_id !== (int) $locked->profile_id) {
                throw ValidationException::withMessages([
                    'lender_strategy_id' => ['Lender strategy must belong to the same portfolio.'],
                ]);
            }

            if ((int) $lender->id === (int) $locked->borrower_strategy_id) {
                throw ValidationException::withMessages([
                    'lender_strategy_id' => ['Lender strategy must differ from borrower strategy.'],
                ]);
            }

            if ($lender->status !== TradingStrategy::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'lender_strategy_id' => ['Lender strategy must be enabled.'],
                ]);
            }

            $amount = (float) $locked->amount;
            if (! CommittedLoanAmount::isValid($amount)) {
                throw ValidationException::withMessages([
                    'amount' => ['Requested amount must be at least ₹5,000 and a whole multiple of ₹5,000.'],
                ]);
            }

            $profile = PortfolioProfile::query()->findOrFail($locked->profile_id);
            $eligible = $this->eligibility->eligibleLenders(
                $profile,
                (int) $locked->borrower_strategy_id,
                $amount,
            );
            $match = null;
            foreach ($eligible as $row) {
                if ((int) $row['strategy_id'] === (int) $lender->id) {
                    $match = $row;
                    break;
                }
            }

            if ($match === null) {
                $locked->forceFill([
                    'status' => CapitalRequest::STATUS_REVALIDATION_FAILED,
                ])->save();

                return ['ok' => false];
            }

            $committedAt = now();
            $locked->forceFill([
                'lender_strategy_id' => $lender->id,
                'status' => CapitalRequest::STATUS_COMMITTED,
                'approved_at' => $committedAt,
                'approved_by' => $approver->id,
            ])->save();

            $loan = CapitalLoan::query()->create([
                'profile_id' => $locked->profile_id,
                'capital_request_id' => $locked->id,
                'borrower_strategy_id' => $locked->borrower_strategy_id,
                'lender_strategy_id' => $lender->id,
                'principal' => $amount,
                'outstanding' => $amount,
                'committed_at' => $committedAt,
                'min_recall_at' => null,
                'status' => CapitalLoan::STATUS_OUTSTANDING,
            ]);

            $locked->setRelation('loan', $loan);
            $this->lending->markCapitalCommitted($locked);

            return ['ok' => true, 'loan' => $loan];
        });

        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'lender_strategy_id' => ['Lender is ineligible or does not have enough available-for-lending at approval time.'],
            ]);
        }

        return $result['loan'];
    }

    public function reject(CapitalRequest $request, User $approver): CapitalRequest
    {
        return DB::transaction(function () use ($request, $approver) {
            /** @var CapitalRequest $locked */
            $locked = CapitalRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CapitalRequest::STATUS_COMMITTED || $locked->loan()->exists()) {
                throw ValidationException::withMessages([
                    'capital_request' => ['A committed capital request cannot be rejected.'],
                ]);
            }

            if ($locked->status === CapitalRequest::STATUS_REJECTED) {
                throw ValidationException::withMessages([
                    'capital_request' => ['This capital request is already rejected.'],
                ]);
            }

            if (! $locked->isApprovable()) {
                throw ValidationException::withMessages([
                    'capital_request' => ['This capital request cannot be rejected in its current status.'],
                ]);
            }

            $locked->forceFill([
                'status' => CapitalRequest::STATUS_REJECTED,
                'approved_by' => $approver->id,
            ])->save();

            return $locked->fresh();
        });
    }
}
