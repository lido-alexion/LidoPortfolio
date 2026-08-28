<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
use App\Models\CapitalRecall;
use App\Models\CashLedgerEntry;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Services\CashManagementService;
use Illuminate\Support\Facades\DB;

/**
 * V4-SPEC-004 — post signed LOAN / RECALL / BRIDGE onto the existing cash ledger.
 *
 * Intra-portfolio capital events do not change physical cash: the same type is posted
 * once positive (money enters trading cash) and once negative (money leaves) so the
 * pool nets to zero. Delayed sale proceeds are a real inflow and post a single positive row.
 * Optional notes are human context only — not a chart of accounts.
 */
final class SpecialCashMovementService
{
    public function __construct(
        protected CashManagementService $cash,
    ) {}

    public function postLoanDisbursement(PortfolioProfile $profile, CapitalLoan $loan): void
    {
        $loan->loadMissing('capitalRequest');
        $amount = round((float) $loan->principal, 4);
        $this->postWash(
            $profile,
            CashLedgerEntry::TYPE_LOAN,
            $amount,
            'Loan #'.$loan->id.' received',
            'Loan #'.$loan->id.' provided',
            recommendationId: $loan->capitalRequest?->recommendation_id
                ? (int) $loan->capitalRequest->recommendation_id
                : null,
        );
    }

    public function postLoanRepayment(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        CapitalLoanReturn $return,
    ): void {
        $loan->loadMissing('capitalRequest');
        $amount = round((float) $return->amount, 4);
        $this->postWash(
            $profile,
            CashLedgerEntry::TYPE_LOAN,
            $amount,
            'Loan #'.$loan->id.' return #'.$return->id.' received',
            'Loan #'.$loan->id.' return #'.$return->id.' provided',
            recommendationId: $loan->capitalRequest?->recommendation_id
                ? (int) $loan->capitalRequest->recommendation_id
                : null,
        );
    }

    public function postRecallSettlement(PortfolioProfile $profile, CapitalRecall $recall, float $amount): void
    {
        $amount = round($amount, 4);
        $settledAfter = round((float) $recall->settled_amount, 4);
        $this->postWash(
            $profile,
            CashLedgerEntry::TYPE_RECALL,
            $amount,
            'Recall #'.$recall->id.' settlement to '.$this->amountKey($settledAfter).' received',
            'Recall #'.$recall->id.' settlement to '.$this->amountKey($settledAfter).' provided',
        );
    }

    public function postBridgeDisbursement(PortfolioProfile $profile, RecallBridgeLoan $loan): void
    {
        $amount = round((float) $loan->principal, 4);
        $this->postWash(
            $profile,
            CashLedgerEntry::TYPE_BRIDGE,
            $amount,
            'Recall Bridge Loan #'.$loan->id.' received',
            'Recall Bridge Loan #'.$loan->id.' provided',
        );
    }

    public function postBridgeRepayment(
        PortfolioProfile $profile,
        RecallBridgeLoan $loan,
        float $amount,
        float $outstandingAfter,
    ): void {
        $amount = round($amount, 4);
        $this->postWash(
            $profile,
            CashLedgerEntry::TYPE_BRIDGE,
            $amount,
            'Recall Bridge Loan #'.$loan->id.' repayment remaining '.$this->amountKey($outstandingAfter).' received',
            'Recall Bridge Loan #'.$loan->id.' repayment remaining '.$this->amountKey($outstandingAfter).' provided',
        );
    }

    /**
     * Delayed Proceeds from Stock Sale enter the physical pool (applyCash was false on the SELL).
     */
    public function postProceedsRelease(PortfolioProfile $profile, PendingSaleProceeds $row): void
    {
        $amount = round((float) $row->amount, 4);
        if ($amount <= 0.0001) {
            return;
        }

        $type = $row->obligation_type === PendingSaleProceeds::OBLIGATION_BRIDGE
            ? CashLedgerEntry::TYPE_BRIDGE
            : CashLedgerEntry::TYPE_RECALL;
        $reason = 'Proceeds from Stock Sale'
            .($row->transaction_id ? ' (tx #'.$row->transaction_id.')' : '');

        if ($this->alreadyPosted($profile->id, $type, $reason)) {
            return;
        }

        $this->cash->postSpecialMovement(
            $profile,
            $type,
            $amount,
            $reason,
            null,
            null,
            $row->transaction_id ? (int) $row->transaction_id : null,
        );
    }

    private function postWash(
        PortfolioProfile $profile,
        string $type,
        float $amount,
        string $inboundReason,
        string $outboundReason,
        ?int $transactionId = null,
        ?int $recommendationId = null,
    ): void {
        $amount = round(abs($amount), 4);
        if ($amount <= 0.0001) {
            return;
        }
        if ($this->alreadyPosted($profile->id, $type, $inboundReason)) {
            return;
        }

        DB::transaction(function () use (
            $profile,
            $type,
            $amount,
            $inboundReason,
            $outboundReason,
            $transactionId,
            $recommendationId,
        ) {
            // Credit first so the matching debit cannot fail insufficient-cash on the single pool.
            $this->cash->postSpecialMovement(
                $profile,
                $type,
                $amount,
                $inboundReason,
                null,
                null,
                $transactionId,
                $recommendationId,
            );
            $this->cash->postSpecialMovement(
                $profile,
                $type,
                -$amount,
                $outboundReason,
                null,
                null,
                $transactionId,
                $recommendationId,
            );
        });
    }

    private function alreadyPosted(int $profileId, string $type, string $inboundReason): bool
    {
        return CashLedgerEntry::query()
            ->where('profile_id', $profileId)
            ->where('entry_type', $type)
            ->where('reason', $inboundReason)
            ->exists();
    }

    private function amountKey(float $amount): string
    {
        return number_format(round($amount, 4), 4, '.', '');
    }
}
