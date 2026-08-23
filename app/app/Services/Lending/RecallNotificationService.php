<?php

namespace App\Services\Lending;

use App\Engines\Notification\NotificationEngine;
use App\Models\CapitalRecall;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Services\Notification\NotificationMessageComposer;
use Illuminate\Support\Facades\DB;

/**
 * Domain → Telegram notifications for recall / bridge / proceeds (Phase 3B-2).
 * Uses NotificationEngine idempotency keys — no parallel delivery stack.
 */
final class RecallNotificationService
{
    public function __construct(
        protected NotificationEngine $engine,
        protected NotificationMessageComposer $composer,
    ) {}

    public function recallRequested(PortfolioProfile $profile, CapitalRecall $recall): void
    {
        $this->afterCommit(function () use ($profile, $recall) {
            $recall = $recall->fresh(['lenderStrategy', 'borrowerStrategy']) ?? $recall;
            $key = 'recall-'.$recall->id.'-requested-telegram';
            $this->engine->notifyDomain(
                $profile,
                'recall_requested',
                $key,
                $this->composer->recallRequestedMessage([
                    'amount' => (float) $recall->recall_amount,
                    'kind_label' => $recall->kind === CapitalRecall::KIND_FULL ? 'Full Recall' : 'Partial Recall',
                    'lender' => $this->strategyName($recall->lenderStrategy, $recall->lender_strategy_id),
                    'borrower' => $this->strategyName($recall->borrowerStrategy, $recall->borrower_strategy_id),
                    'loan_id' => $recall->loan_id,
                    'recall_id' => $recall->id,
                    'state_label' => 'Requested',
                ]),
                [
                    'recall_id' => $recall->id,
                    'loan_id' => $recall->loan_id,
                    'amount' => (float) $recall->recall_amount,
                    'state' => $recall->state,
                ],
            );
        });
    }

    public function recallPendingHeld(PortfolioProfile $profile, CapitalRecall $recall): void
    {
        $this->afterCommit(function () use ($profile, $recall) {
            $recall = $recall->fresh(['lenderStrategy', 'borrowerStrategy']) ?? $recall;
            $key = 'recall-'.$recall->id.'-pending_held-telegram';
            $this->engine->notifyDomain(
                $profile,
                'recall_pending_held',
                $key,
                $this->composer->recallPendingHeldMessage([
                    'amount' => (float) $recall->recall_amount,
                    'settled' => (float) $recall->settled_amount,
                    'outstanding' => (float) $recall->outstanding_recall_amount,
                    'lender' => $this->strategyName($recall->lenderStrategy, $recall->lender_strategy_id),
                    'borrower' => $this->strategyName($recall->borrowerStrategy, $recall->borrower_strategy_id),
                    'recall_id' => $recall->id,
                ]),
                [
                    'recall_id' => $recall->id,
                    'state' => CapitalRecall::STATE_PENDING_HELD,
                ],
            );
        });
    }

    /**
     * One notification per settlement batch (settled_total after apply).
     */
    public function recallSettlementBatch(
        PortfolioProfile $profile,
        CapitalRecall $recall,
        float $settledNow,
    ): void {
        $this->afterCommit(function () use ($profile, $recall, $settledNow) {
            $recall = $recall->fresh(['lenderStrategy', 'borrowerStrategy']) ?? $recall;
            $settledTotal = round((float) $recall->settled_amount, 4);
            $key = 'recall-'.$recall->id.'-settlement-'.number_format($settledTotal, 4, '.', '').'-telegram';
            $completed = $recall->state === CapitalRecall::STATE_COMPLETED
                || (float) $recall->outstanding_recall_amount <= 0.0001;

            $this->engine->notifyDomain(
                $profile,
                $completed ? 'recall_completed' : 'recall_settlement',
                $key,
                $completed
                    ? $this->composer->recallCompletedMessage([
                        'recall_id' => $recall->id,
                        'amount' => (float) $recall->recall_amount,
                        'lender' => $this->strategyName($recall->lenderStrategy, $recall->lender_strategy_id),
                        'borrower' => $this->strategyName($recall->borrowerStrategy, $recall->borrower_strategy_id),
                    ])
                    : $this->composer->recallSettlementMessage([
                        'settled_now' => $settledNow,
                        'settled_total' => $settledTotal,
                        'outstanding' => (float) $recall->outstanding_recall_amount,
                        'state_label' => $recall->state,
                        'recall_id' => $recall->id,
                        'completed' => false,
                    ]),
                [
                    'recall_id' => $recall->id,
                    'settled_now' => $settledNow,
                    'settled_total' => $settledTotal,
                    'outstanding' => (float) $recall->outstanding_recall_amount,
                    'state' => $recall->state,
                ],
            );
        });
    }

    public function bridgeCreated(PortfolioProfile $profile, RecallBridgeLoan $bridge): void
    {
        $this->afterCommit(function () use ($profile, $bridge) {
            $bridge = $bridge->fresh(['lenderStrategy', 'borrowerStrategy']) ?? $bridge;
            $key = 'bridge-'.$bridge->id.'-created-telegram';
            $this->engine->notifyDomain(
                $profile,
                'recall_bridge_created',
                $key,
                $this->composer->bridgeCreatedMessage([
                    'principal' => (float) $bridge->principal,
                    'borrower' => $this->strategyName($bridge->borrowerStrategy, $bridge->borrower_strategy_id),
                    'lender' => $this->strategyName($bridge->lenderStrategy, $bridge->lender_strategy_id),
                    'recall_id' => $bridge->capital_recall_id,
                    'bridge_id' => $bridge->id,
                ]),
                [
                    'bridge_id' => $bridge->id,
                    'principal' => (float) $bridge->principal,
                ],
            );
        });
    }

    public function bridgeRepaid(PortfolioProfile $profile, RecallBridgeLoan $bridge, float $paid): void
    {
        $this->afterCommit(function () use ($profile, $bridge, $paid) {
            $bridge = $bridge->fresh(['lenderStrategy', 'borrowerStrategy']) ?? $bridge;
            $outstanding = round((float) $bridge->outstanding, 4);
            $completed = $bridge->status === RecallBridgeLoan::STATUS_RETURNED || $outstanding <= 0.0001;
            $key = 'bridge-'.$bridge->id.'-repay-'.number_format(
                round((float) $bridge->principal - $outstanding, 4),
                4,
                '.',
                ''
            ).'-telegram';

            $this->engine->notifyDomain(
                $profile,
                $completed ? 'recall_bridge_completed' : 'recall_bridge_partial_repay',
                $key,
                $this->composer->bridgeRepaidMessage([
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                    'principal' => (float) $bridge->principal,
                    'borrower' => $this->strategyName($bridge->borrowerStrategy, $bridge->borrower_strategy_id),
                    'lender' => $this->strategyName($bridge->lenderStrategy, $bridge->lender_strategy_id),
                    'bridge_id' => $bridge->id,
                    'completed' => $completed,
                ]),
                [
                    'bridge_id' => $bridge->id,
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                    'status' => $bridge->status,
                ],
            );
        });
    }

    public function saleInitiated(PortfolioProfile $profile, PendingSaleProceeds $row): void
    {
        $this->afterCommit(function () use ($profile, $row) {
            $row = $row->fresh() ?? $row;
            $key = 'proceeds-'.$row->id.'-sale-initiated-telegram';
            $obligation = $row->obligation_type === PendingSaleProceeds::OBLIGATION_BRIDGE
                ? 'Recall Bridge Loan'
                : 'Recall';
            $this->engine->notifyDomain(
                $profile,
                'sale_proceeds_initiated',
                $key,
                $this->composer->saleInitiatedMessage([
                    'expected' => (float) ($row->expected_amount ?? $row->amount),
                    'available_at' => optional($row->available_at)?->toDateTimeString(),
                    'obligation_label' => $obligation,
                ]),
                [
                    'pending_sale_proceeds_id' => $row->id,
                    'status' => $row->status,
                ],
            );
        });
    }

    /**
     * @param  array{applied_to_recall: float, applied_to_bridge: float, excess_retained: float, row: PendingSaleProceeds}  $result
     */
    public function proceedsApplied(PortfolioProfile $profile, array $result): void
    {
        $applied = round(($result['applied_to_recall'] ?? 0) + ($result['applied_to_bridge'] ?? 0), 4);
        if ($applied <= 0.0001) {
            return;
        }
        $row = $result['row'];
        $this->afterCommit(function () use ($profile, $result, $row, $applied) {
            $key = 'proceeds-'.$row->id.'-applied-telegram';
            $this->engine->notifyDomain(
                $profile,
                'sale_proceeds_applied',
                $key,
                $this->composer->proceedsAppliedMessage([
                    'applied' => $applied,
                    'to_recall' => $result['applied_to_recall'] ?? 0,
                    'to_bridge' => $result['applied_to_bridge'] ?? 0,
                    'excess' => $result['excess_retained'] ?? 0,
                ]),
                [
                    'pending_sale_proceeds_id' => $row->id,
                    'applied' => $applied,
                ],
            );
        });
    }

    public function partialCapitalResolution(
        PortfolioProfile $profile,
        float $requested,
        float $actual,
        ?TradingRecommendation $recommendation = null,
    ): void {
        $requested = round($requested, 4);
        $actual = round($actual, 4);
        $unresolved = round(max(0.0, $requested - $actual), 4);
        if ($unresolved <= 0.0001 || $actual <= 0.0001) {
            return;
        }
        // Only when materially short of target (avoid noise for tiny rounding)
        if ($unresolved < 1.0) {
            return;
        }

        $this->afterCommit(function () use ($profile, $requested, $actual, $unresolved, $recommendation) {
            $recId = $recommendation?->id;
            $key = 'capital-partial-'.($recId ?? 'adhoc').'-'
                .number_format($actual, 4, '.', '').'-telegram';
            $this->engine->notifyDomain(
                $profile,
                'capital_resolution_partial',
                $key,
                $this->composer->partialCapitalResolutionMessage([
                    'requested' => $requested,
                    'actual' => $actual,
                    'unresolved' => $unresolved,
                ]),
                [
                    'requested' => $requested,
                    'actual_execution_amount' => $actual,
                    'unresolved' => $unresolved,
                ],
                $recId,
            );
        });
    }

    private function afterCommit(callable $callback): void
    {
        // RefreshDatabase wraps tests in a rolled-back transaction; afterCommit never fires there.
        if (app()->runningUnitTests()) {
            $callback();

            return;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
        } else {
            $callback();
        }
    }

    private function strategyName(?TradingStrategy $strategy, int|string|null $id): string
    {
        if ($strategy && trim((string) $strategy->name) !== '') {
            return (string) $strategy->name;
        }

        return $id ? 'Strategy #'.$id : '—';
    }
}
