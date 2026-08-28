<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apply Proceeds from Stock Sale to recall and/or Recall Bridge Loan obligations.
 * Excess proceeds stay as borrower capital (not transferred to lender).
 */
final class ProceedsApplicationService
{
    public function __construct(
        protected SaleProceedsAvailabilityService $proceeds,
        protected CapitalLoanRepaymentService $repayments,
        protected RecallBridgeLoanService $bridges,
        protected RecallService $recalls,
        protected RecallNotificationService $notifications,
    ) {}

    /**
     * @return array{applied_to_recall: float, applied_to_bridge: float, excess_retained: float, row: PendingSaleProceeds}
     */
    public function applyRow(PendingSaleProceeds $row, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();

        return DB::transaction(function () use ($row, $asOf) {
            /** @var PendingSaleProceeds $locked */
            $locked = PendingSaleProceeds::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PendingSaleProceeds::STATUS_APPLIED) {
                return $this->emptyResult($locked);
            }

            $locked = $this->proceeds->releaseCashIfDue($locked, $asOf);
            $locked = $this->proceeds->refreshStatus($locked, $asOf);

            if ($locked->cash_released_at !== null && $locked->status === PendingSaleProceeds::STATUS_PENDING) {
                $locked->forceFill(['status' => PendingSaleProceeds::STATUS_AVAILABLE])->save();
                $locked = $locked->fresh();
            }

            if ($locked->status !== PendingSaleProceeds::STATUS_AVAILABLE) {
                return $this->emptyResult($locked);
            }

            $pool = round((float) $locked->amount, 4);
            $appliedRecall = 0.0;
            $appliedBridge = 0.0;
            $type = $locked->obligation_type;

            if ($type === PendingSaleProceeds::OBLIGATION_BRIDGE) {
                $appliedBridge = $this->applyToBridge($locked, $pool);
                $pool = round($pool - $appliedBridge, 4);
            } else {
                // Default / recall obligation
                $appliedRecall = $this->applyToRecall($locked, $pool);
                $pool = round($pool - $appliedRecall, 4);
            }

            $this->proceeds->markApplied($locked->fresh(), $asOf);

            $result = [
                'applied_to_recall' => $appliedRecall,
                'applied_to_bridge' => $appliedBridge,
                'excess_retained' => max(0.0, $pool),
                'row' => $locked->fresh(),
            ];
            $profile = PortfolioProfile::query()->find($locked->profile_id);
            if ($profile) {
                $this->notifications->proceedsApplied($profile, $result);
            }

            return $result;
        });
    }

    /**
     * @return array{processed: int, applied_to_recall: float, applied_to_bridge: float}
     */
    public function processDue(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $rows = $this->proceeds->dueForRelease($asOf);
        $processed = 0;
        $toRecall = 0.0;
        $toBridge = 0.0;

        foreach ($rows as $row) {
            $result = $this->applyRow($row, $asOf);
            if ($result['row']->status === PendingSaleProceeds::STATUS_APPLIED) {
                $processed++;
                $toRecall += $result['applied_to_recall'];
                $toBridge += $result['applied_to_bridge'];
            }
        }

        return [
            'processed' => $processed,
            'applied_to_recall' => round($toRecall, 4),
            'applied_to_bridge' => round($toBridge, 4),
        ];
    }

    private function applyToBridge(PendingSaleProceeds $locked, float $pool): float
    {
        $bridge = RecallBridgeLoan::query()->find($locked->recall_bridge_loan_id);
        if (! $bridge || (float) $bridge->outstanding <= 0) {
            return 0.0;
        }
        $pay = round(min($pool, (float) $bridge->outstanding), 4);
        if ($pay <= 0) {
            return 0.0;
        }
        $this->bridges->repay($bridge, $pay, postCash: false);

        return $pay;
    }

    private function applyToRecall(PendingSaleProceeds $locked, float $pool): float
    {
        $recall = CapitalRecall::query()->find($locked->capital_recall_id);
        if (! $recall || (float) $recall->outstanding_recall_amount <= 0) {
            return 0.0;
        }
        $pay = round(min($pool, (float) $recall->outstanding_recall_amount), 4);
        if ($pay <= 0) {
            return 0.0;
        }

        $loan = CapitalLoan::query()->find($recall->loan_id);
        if ($loan && (float) $loan->outstanding > 0) {
            $loanPay = round(min($pay, (float) $loan->outstanding), 4);
            if ($loanPay > 0) {
                $this->repayments->repay($loan, $loanPay, postCash: false);
            }
        }

        if ($recall->state !== CapitalRecall::STATE_SETTLEMENT
            && $recall->state !== CapitalRecall::STATE_COMPLETED) {
            $this->recalls->markSettlement($recall);
        }
        $this->recalls->applySettlementAmount($recall->fresh(), $pay);

        return $pay;
    }

    /**
     * @return array{applied_to_recall: float, applied_to_bridge: float, excess_retained: float, row: PendingSaleProceeds}
     */
    private function emptyResult(PendingSaleProceeds $row): array
    {
        return [
            'applied_to_recall' => 0.0,
            'applied_to_bridge' => 0.0,
            'excess_retained' => 0.0,
            'row' => $row,
        ];
    }
}
