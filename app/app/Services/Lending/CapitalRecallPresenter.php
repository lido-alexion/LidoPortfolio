<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\PendingSaleProceeds;
use App\Models\RecallBridgeLoan;
use App\Models\TradingStrategy;

/**
 * Thin API presentation helpers for recall / bridge / proceeds (no business rules).
 */
final class CapitalRecallPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function recall(CapitalRecall $recall, bool $detailed = false): array
    {
        $recall->loadMissing(['loan', 'lenderStrategy', 'borrowerStrategy']);

        $payload = [
            'id' => $recall->id,
            'loan_id' => $recall->loan_id,
            'lender_strategy_id' => $recall->lender_strategy_id,
            'borrower_strategy_id' => $recall->borrower_strategy_id,
            'lender_strategy_name' => $recall->lenderStrategy?->name,
            'borrower_strategy_name' => $recall->borrowerStrategy?->name,
            'kind' => $recall->kind,
            'recall_amount' => (float) $recall->recall_amount,
            'settled_amount' => (float) $recall->settled_amount,
            'outstanding_recall_amount' => (float) $recall->outstanding_recall_amount,
            'state' => $recall->state,
            'is_active' => $recall->isActive(),
            'is_completed' => $recall->isCompleted(),
            'requested_at' => $recall->requested_at?->toIso8601String(),
            'pending_held_at' => $recall->pending_held_at?->toIso8601String(),
            'completed_at' => $recall->completed_at?->toIso8601String(),
            'loan' => $recall->loan ? [
                'id' => $recall->loan->id,
                'principal' => (float) $recall->loan->principal,
                'outstanding' => (float) $recall->loan->outstanding,
                'status' => $recall->loan->status,
                'committed_at' => $recall->loan->committed_at?->toIso8601String(),
            ] : null,
        ];

        if (! $detailed) {
            return $payload;
        }

        $bridges = RecallBridgeLoan::query()
            ->where('capital_recall_id', $recall->id)
            ->orderBy('id')
            ->get();
        $proceeds = PendingSaleProceeds::query()
            ->where('capital_recall_id', $recall->id)
            ->orderBy('id')
            ->get();

        $bridgePrincipal = round($bridges->sum(fn ($b) => (float) $b->principal), 4);
        $expectedProceeds = round($proceeds->sum(fn ($p) => (float) ($p->expected_amount ?? $p->amount)), 4);
        $actualProceeds = round($proceeds->sum(fn ($p) => (float) $p->amount), 4);
        $appliedProceeds = round(
            $proceeds->where('status', PendingSaleProceeds::STATUS_APPLIED)->sum(fn ($p) => (float) $p->amount),
            4
        );

        $payload['immediate_settlement_amount'] = (float) $recall->settled_amount;
        $payload['bridge_amount'] = $bridgePrincipal;
        $payload['bridge_loans'] = $bridges->map(fn (RecallBridgeLoan $b) => $this->bridgeLoan($b))->all();
        $payload['liquidation'] = [
            'expected_proceeds' => $expectedProceeds,
            'actual_proceeds' => $actualProceeds,
            'applied_proceeds' => $appliedProceeds,
            'pending_proceeds_count' => $proceeds->whereIn('status', [
                PendingSaleProceeds::STATUS_PENDING,
                PendingSaleProceeds::STATUS_AVAILABLE,
            ])->count(),
        ];
        $payload['pending_sale_proceeds'] = $proceeds->map(fn (PendingSaleProceeds $p) => $this->pendingProceeds($p))->all();
        $payload['terminology'] = [
            'proceeds_label' => 'Proceeds from Stock Sale',
            'bridge_label' => 'Recall Bridge Loan',
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function bridgeLoan(RecallBridgeLoan $loan, bool $detailed = false): array
    {
        $loan->loadMissing(['lenderStrategy', 'borrowerStrategy', 'capitalRecall']);

        $payload = [
            'id' => $loan->id,
            'capital_recall_id' => $loan->capital_recall_id,
            'lender_strategy_id' => $loan->lender_strategy_id,
            'borrower_strategy_id' => $loan->borrower_strategy_id,
            'lender_strategy_name' => $loan->lenderStrategy?->name,
            'borrower_strategy_name' => $loan->borrowerStrategy?->name,
            'principal' => (float) $loan->principal,
            'outstanding' => (float) $loan->outstanding,
            'repaid_amount' => round((float) $loan->principal - (float) $loan->outstanding, 4),
            'status' => $loan->status,
            'committed_at' => $loan->committed_at?->toIso8601String(),
            'created_at' => $loan->created_at?->toIso8601String(),
            'is_recallable' => false,
            'label' => 'Recall Bridge Loan',
        ];

        if ($detailed) {
            $payload['capital_recall'] = $loan->capitalRecall
                ? $this->recall($loan->capitalRecall, false)
                : null;
            $payload['repayment'] = [
                'principal' => (float) $loan->principal,
                'outstanding' => (float) $loan->outstanding,
                'repaid_amount' => $payload['repaid_amount'],
                'status' => $loan->status,
                'note' => 'Any amount ≤ outstanding; not ₹5,000 multiples. No interest.',
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function pendingProceeds(PendingSaleProceeds $row): array
    {
        $row->loadMissing(['transaction', 'strategy']);
        $actual = (float) $row->amount;
        $expected = $row->expected_amount !== null ? (float) $row->expected_amount : $actual;
        $applied = $row->status === PendingSaleProceeds::STATUS_APPLIED ? $actual : 0.0;
        $remaining = $row->status === PendingSaleProceeds::STATUS_APPLIED ? 0.0 : $actual;

        return [
            'id' => $row->id,
            'label' => 'Proceeds from Stock Sale',
            'strategy_id' => $row->strategy_id,
            'strategy_name' => $row->strategy?->name,
            'capital_recall_id' => $row->capital_recall_id,
            'recall_bridge_loan_id' => $row->recall_bridge_loan_id,
            'obligation_type' => $row->obligation_type,
            'transaction_id' => $row->transaction_id,
            'originating_stock_sale' => $row->transaction ? [
                'transaction_id' => $row->transaction->id,
                'stock_id' => $row->transaction->stock_id,
                'quantity' => (float) $row->transaction->quantity,
                'price' => (float) $row->transaction->price,
                'transaction_date' => $row->transaction->transaction_date?->toDateString(),
                'notes' => $row->transaction->notes,
            ] : null,
            'expected_amount' => $expected,
            'actual_proceeds_amount' => $actual,
            'required_settlement_amount' => $row->required_settlement_amount !== null
                ? (float) $row->required_settlement_amount : null,
            'target_liquidation_value' => $row->target_liquidation_value !== null
                ? (float) $row->target_liquidation_value : null,
            'sale_buffer_amount' => $row->sale_buffer_amount !== null
                ? (float) $row->sale_buffer_amount : null,
            'sold_at' => $row->sold_at?->toIso8601String(),
            'available_at' => $row->available_at?->toIso8601String(),
            'status' => $row->status,
            'cash_released_at' => $row->cash_released_at?->toIso8601String(),
            'applied_at' => $row->applied_at?->toIso8601String(),
            'amount_applied' => $applied,
            'amount_remaining' => $remaining,
        ];
    }

    public function strategyName(?TradingStrategy $strategy): ?string
    {
        return $strategy?->name;
    }

    /**
     * Classify cash-ledger reasons for UI history (no parallel ledger).
     */
    public function cashMovementKind(?string $reason): string
    {
        $r = strtolower((string) $reason);
        if (str_contains($r, 'proceeds from stock sale')) {
            return 'proceeds_from_stock_sale';
        }
        if (str_contains($r, 'recall bridge') || str_contains($r, 'bridge loan')) {
            return 'recall_bridge_loan';
        }
        if (str_contains($r, 'recall')) {
            return 'recall';
        }
        if (str_contains($r, 'loan repay') || str_contains($r, 'repayment')) {
            return 'normal_loan_repayment';
        }
        if (str_contains($r, 'loan')) {
            return 'normal_loan';
        }

        return 'other';
    }
}
