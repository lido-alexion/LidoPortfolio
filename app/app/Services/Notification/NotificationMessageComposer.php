<?php

namespace App\Services\Notification;

use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;

/**
 * Owns notification message text formatting (recommendation + alert channels).
 * Dispatch (queueing, idempotency, Telegram send, status updates) stays with the
 * calling engine/service (TD-005: separate composition from dispatch).
 */
class NotificationMessageComposer
{
    public function recommendationMessage(TradingRecommendation $rec): string
    {
        $symbol = $rec->security?->symbol ?? '#'.$rec->security_id;
        $lines = [
            'Lido Trading OS recommendation',
            sprintf('%s %s (priority %d)', $rec->recommendation_type, $symbol, $rec->priority),
            sprintf('Confidence: %.0f%% | Risk: %s', ((float) $rec->confidence) * 100, $rec->risk_level),
        ];
        if ($rec->suggested_position_size) {
            $lines[] = sprintf('Suggested size: ₹%s', number_format((float) $rec->suggested_position_size, 0));
        }
        $capitalStatus = $rec->capitalAllocationStatus();
        if (in_array($capitalStatus, [
            TradingRecommendation::ALLOCATION_UNFUNDED,
            TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
        ], true)) {
            $lines[] = 'Capital: '.$this->capitalStatusLabel($capitalStatus);
            $target = $rec->capitalTargetAmount();
            $own = $rec->ownAllocatedAmount();
            if ($target !== null) {
                $lines[] = sprintf(
                    'Target ₹%s · This cycle ₹%s',
                    $this->inr($target),
                    $this->inr($own ?? 0)
                );
            }
        }
        $exitReason = $rec->primaryExitReason();
        if ($exitReason !== null && $exitReason !== '') {
            $lines[] = 'Exit reason: '.$this->exitReasonLabel($exitReason);
        }
        $passed = $rec->evidence['passed_rules'] ?? [];
        if ($passed) {
            $lines[] = 'Passed: '.implode(', ', array_slice($passed, 0, 5));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     */
    public function alertsMessage(array $alerts): string
    {
        $lines = ['Portfolio alerts ('.count($alerts).')'];

        foreach ($alerts as $alert) {
            $symbol = $alert['stock']['symbol'] ?? $alert['stock']['name'] ?? 'Unknown';
            $message = trim((string) ($alert['message'] ?? ''));
            $lines[] = $message !== '' ? "• {$symbol}: {$message}" : "• {$symbol}";
        }

        return implode("\n", $lines);
    }

    public function clearPingMessage(PortfolioProfile $profile, ?string $atTime = null): string
    {
        $name = trim((string) $profile->name) !== '' ? $profile->name : 'Portfolio';
        $timeLabel = $atTime ? " (scheduled check at {$atTime})" : '';

        return "✅ Lido Portfolio — {$name}: No active alerts{$timeLabel}.\n\n"
            .'Scheduled notification check — cron is working. Disable “Ping Telegram when clear” in Settings → Global when done testing.';
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function recallRequestedMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Recall requested',
            sprintf('Amount: ₹%s (%s)', $this->inr($ctx['amount'] ?? 0), $ctx['kind_label'] ?? 'Recall'),
            sprintf('Lender: %s', $ctx['lender'] ?? '—'),
            sprintf('Borrower: %s', $ctx['borrower'] ?? '—'),
            sprintf('Loan #%s · Recall #%s', $ctx['loan_id'] ?? '—', $ctx['recall_id'] ?? '—'),
            sprintf('State: %s', $ctx['state_label'] ?? 'Requested'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function recallPendingHeldMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Recall pending',
            'Funds are being arranged (liquidation / Proceeds from Stock Sale).',
            'The recall is not completed yet; settlement happens when funds become available.',
            sprintf('Recall amount: ₹%s', $this->inr($ctx['amount'] ?? 0)),
            sprintf('Already settled: ₹%s · Outstanding: ₹%s', $this->inr($ctx['settled'] ?? 0), $this->inr($ctx['outstanding'] ?? 0)),
            sprintf('Lender: %s · Borrower: %s', $ctx['lender'] ?? '—', $ctx['borrower'] ?? '—'),
            sprintf('Recall #%s', $ctx['recall_id'] ?? '—'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function recallSettlementMessage(array $ctx): string
    {
        $lines = [
            'Lido Portfolio — Recall settlement',
            sprintf('Settled this time: ₹%s', $this->inr($ctx['settled_now'] ?? 0)),
            sprintf('Total settled: ₹%s · Outstanding: ₹%s', $this->inr($ctx['settled_total'] ?? 0), $this->inr($ctx['outstanding'] ?? 0)),
            sprintf('State: %s', $ctx['state_label'] ?? 'Settlement'),
            sprintf('Recall #%s', $ctx['recall_id'] ?? '—'),
        ];
        if (! empty($ctx['completed'])) {
            $lines[] = 'Recall completed.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function recallCompletedMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Recall completed',
            sprintf('Recall #%s fully settled (₹%s).', $ctx['recall_id'] ?? '—', $this->inr($ctx['amount'] ?? 0)),
            sprintf('Lender: %s · Borrower: %s', $ctx['lender'] ?? '—', $ctx['borrower'] ?? '—'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function bridgeCreatedMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Recall Bridge Loan created',
            sprintf('Amount: ₹%s', $this->inr($ctx['principal'] ?? 0)),
            sprintf('Borrower: %s · Lender: %s', $ctx['borrower'] ?? '—', $ctx['lender'] ?? '—'),
            sprintf('Linked recall #%s · Bridge #%s', $ctx['recall_id'] ?? '—', $ctx['bridge_id'] ?? '—'),
            'Used only to fulfil a recall — not for investing; cannot itself be recalled.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function bridgeRepaidMessage(array $ctx): string
    {
        $title = ! empty($ctx['completed'])
            ? 'Lido Portfolio — Recall Bridge Loan repaid'
            : 'Lido Portfolio — Recall Bridge Loan partial repayment';

        return implode("\n", [
            $title,
            sprintf('Repaid this time: ₹%s', $this->inr($ctx['paid'] ?? 0)),
            sprintf('Outstanding: ₹%s of ₹%s', $this->inr($ctx['outstanding'] ?? 0), $this->inr($ctx['principal'] ?? 0)),
            sprintf('Borrower: %s · Lender: %s', $ctx['borrower'] ?? '—', $ctx['lender'] ?? '—'),
            sprintf('Bridge #%s', $ctx['bridge_id'] ?? '—'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function saleInitiatedMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Stock sale for recall/bridge',
            'Sale executed — Proceeds from Stock Sale are not yet available cash.',
            sprintf('Expected proceeds: ₹%s', $this->inr($ctx['expected'] ?? 0)),
            sprintf('Available after: %s', $ctx['available_at'] ?? 'settlement delay'),
            sprintf('Obligation: %s', $ctx['obligation_label'] ?? 'Recall'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function proceedsAppliedMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Proceeds from Stock Sale applied',
            'Proceeds are now available and have been applied.',
            sprintf('Applied: ₹%s', $this->inr($ctx['applied'] ?? 0)),
            sprintf('To recall: ₹%s · To Recall Bridge Loan: ₹%s', $this->inr($ctx['to_recall'] ?? 0), $this->inr($ctx['to_bridge'] ?? 0)),
            sprintf('Excess retained as own capital: ₹%s', $this->inr($ctx['excess'] ?? 0)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function partialCapitalResolutionMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Partial capital funding',
            sprintf('Requested: ₹%s', $this->inr($ctx['requested'] ?? 0)),
            sprintf('Actual execution amount: ₹%s', $this->inr($ctx['actual'] ?? 0)),
            sprintf('Unresolved: ₹%s', $this->inr($ctx['unresolved'] ?? 0)),
            'The recommendation will use only the capital actually available.',
        ]);
    }

    /**
     * §30 — capital required for valid UNFUNDED / PARTIALLY_FUNDED / AWAITING_LENDER BUY.
     *
     * @param  array<string, mixed>  $ctx
     */
    public function capitalRequiredMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Capital required',
            sprintf('%s %s', $ctx['action'] ?? 'BUY', $ctx['symbol'] ?? '—'),
            sprintf('Status: %s', $this->capitalStatusLabel($ctx['status'] ?? '')),
            sprintf('Target: ₹%s · Available this cycle: ₹%s', $this->inr($ctx['target'] ?? 0), $this->inr($ctx['available'] ?? 0)),
            'This is an actionable OPEN/INCREASE — not a HOLD/WATCH skip.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function lendingCommitmentMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Lending commitment',
            sprintf('Loan #%s committed for ₹%s', $ctx['loan_id'] ?? '—', $this->inr($ctx['amount'] ?? 0)),
            sprintf('Lender: %s · Borrower: %s', $ctx['lender'] ?? '—', $ctx['borrower'] ?? '—'),
            sprintf('Capital request #%s', $ctx['request_id'] ?? '—'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function lendingFailureMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Lending failure',
            sprintf('Capital request #%s: %s', $ctx['request_id'] ?? '—', $ctx['reason_label'] ?? 'Failed'),
            sprintf('Amount: ₹%s', $this->inr($ctx['amount'] ?? 0)),
            sprintf('Borrower: %s', $ctx['borrower'] ?? '—'),
            'Capital remains required until a lender commits or own cash is available.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function capitalCommittedMessage(array $ctx): string
    {
        return implode("\n", [
            'Lido Portfolio — Capital committed / execution ready',
            sprintf('%s %s', $ctx['action'] ?? 'BUY', $ctx['symbol'] ?? '—'),
            sprintf('Executable amount: ₹%s', $this->inr($ctx['executable'] ?? 0)),
            sprintf('Loan #%s · Request #%s', $ctx['loan_id'] ?? '—', $ctx['request_id'] ?? '—'),
            'Approve the recommendation, then record the broker fill (loan commitment does not auto-execute).',
        ]);
    }

    private function capitalStatusLabel(string $status): string
    {
        return match ($status) {
            TradingRecommendation::ALLOCATION_UNFUNDED => 'Capital required (UNFUNDED)',
            TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED => 'Partially funded',
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION => 'Awaiting lender selection',
            TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED => 'Capital committed',
            TradingRecommendation::ALLOCATION_FUNDED => 'Funded',
            default => $status !== '' ? $status : 'Unknown',
        };
    }

    private function exitReasonLabel(string $reason): string
    {
        return match ($reason) {
            'strategy_exit' => 'Strategy exit',
            'stop_loss' => 'Portfolio stop-loss',
            'trailing_stop' => 'Portfolio trailing stop',
            'horizon_expiry' => 'Horizon expiry',
            default => $reason,
        };
    }

    private function inr(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 0);
    }
}
