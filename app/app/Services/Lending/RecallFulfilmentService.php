<?php

namespace App\Services\Lending;

use App\Models\CapitalRecall;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingStrategy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Orchestrates pending_held / partial-immediate recalls through liquidation + proceeds.
 */
final class RecallFulfilmentService
{
    public function __construct(
        protected RecallLiquidationService $liquidation,
        protected ProceedsApplicationService $application,
        protected SaleProceedsAvailabilityService $proceeds,
        protected RecallService $recalls,
    ) {}

    /**
     * After Phase 1 immediate settlement: liquidate for remaining recall and/or bridge repayment.
     *
     * @param  array{recall: CapitalRecall, evaluation: array<string, mixed>, bridge_loan: ?RecallBridgeLoan}  $applyResult
     * @return array<string, mixed>
     */
    public function afterImmediateSettlement(
        PortfolioProfile $profile,
        array $applyResult,
        ?CarbonInterface $asOf = null,
        ?float $actualProceedsHaircutRatio = null,
    ): array {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $out = [
            'recall_followup' => null,
            'bridge_followup' => null,
        ];

        /** @var CapitalRecall $recall */
        $recall = $applyResult['recall'];
        $bridge = $applyResult['bridge_loan'] ?? null;

        if ($bridge instanceof RecallBridgeLoan && (float) $bridge->outstanding > 0) {
            $out['bridge_followup'] = $this->liquidateToRepayBridge(
                $profile,
                $bridge,
                $asOf,
                $actualProceedsHaircutRatio,
            );
        }

        $recall = $recall->fresh();
        if ($recall && (float) $recall->outstanding_recall_amount > 0) {
            $out['recall_followup'] = $this->continueRecallFulfilment(
                $profile,
                $recall,
                $asOf,
                $actualProceedsHaircutRatio,
            );
        }

        return $out;
    }

    /**
     * Continue fulfilment for a recall that still has outstanding_recall_amount.
     * Liquidates only the unresolved gap (no unnecessary liquidation).
     *
     * @return array<string, mixed>
     */
    public function continueRecallFulfilment(
        PortfolioProfile $profile,
        CapitalRecall $recall,
        ?CarbonInterface $asOf = null,
        ?float $actualProceedsHaircutRatio = null,
    ): array {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $recall = $recall->fresh();
        $outstanding = round((float) $recall->outstanding_recall_amount, 4);

        if ($outstanding <= 0.0001 || $recall->state === CapitalRecall::STATE_COMPLETED) {
            return [
                'recall' => $recall,
                'liquidated' => false,
                'reason' => 'already_complete',
            ];
        }

        // Skip new liquidation if pending (unapplied) proceeds already cover the need
        $pendingCover = PendingSaleProceeds::query()
            ->where('capital_recall_id', $recall->id)
            ->where('obligation_type', PendingSaleProceeds::OBLIGATION_RECALL)
            ->whereIn('status', [
                PendingSaleProceeds::STATUS_PENDING,
                PendingSaleProceeds::STATUS_AVAILABLE,
            ])
            ->sum('amount');
        $pendingCover = round((float) $pendingCover, 4);
        if ($pendingCover + 0.0001 >= $outstanding) {
            return [
                'recall' => $recall,
                'liquidated' => false,
                'reason' => 'pending_proceeds_cover_obligation',
                'pending_cover' => $pendingCover,
            ];
        }

        $stillNeeded = round(max(0.0, $outstanding - $pendingCover), 4);
        $borrower = TradingStrategy::query()->findOrFail((int) $recall->borrower_strategy_id);

        $result = $this->liquidation->liquidateForObligation(
            profile: $profile,
            borrower: $borrower,
            requiredSettlementAmount: $stillNeeded,
            obligationType: PendingSaleProceeds::OBLIGATION_RECALL,
            recall: $recall,
            bridgeLoan: null,
            soldAt: $asOf,
            actualProceedsHaircutRatio: $actualProceedsHaircutRatio,
        );

        return [
            'recall' => $recall->fresh(),
            'liquidated' => count($result['sales']) > 0,
            'liquidation' => $result,
        ];
    }

    /**
     * After bridge-funded immediate settlement, liquidate to repay the bridge (not recall the bridge lender).
     *
     * @return array<string, mixed>
     */
    public function liquidateToRepayBridge(
        PortfolioProfile $profile,
        RecallBridgeLoan $bridge,
        ?CarbonInterface $asOf = null,
        ?float $actualProceedsHaircutRatio = null,
    ): array {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $bridge = $bridge->fresh();
        $outstanding = round((float) $bridge->outstanding, 4);
        if ($outstanding <= 0.0001) {
            return [
                'bridge' => $bridge,
                'liquidated' => false,
                'reason' => 'bridge_already_repaid',
            ];
        }

        $pendingCover = PendingSaleProceeds::query()
            ->where('recall_bridge_loan_id', $bridge->id)
            ->whereIn('status', [
                PendingSaleProceeds::STATUS_PENDING,
                PendingSaleProceeds::STATUS_AVAILABLE,
            ])
            ->sum('amount');
        $pendingCover = round((float) $pendingCover, 4);
        if ($pendingCover + 0.0001 >= $outstanding) {
            return [
                'bridge' => $bridge,
                'liquidated' => false,
                'reason' => 'pending_proceeds_cover_obligation',
            ];
        }

        $stillNeeded = round(max(0.0, $outstanding - $pendingCover), 4);
        $borrower = TradingStrategy::query()->findOrFail((int) $bridge->borrower_strategy_id);
        $recall = CapitalRecall::query()->find($bridge->capital_recall_id);

        $result = $this->liquidation->liquidateForObligation(
            profile: $profile,
            borrower: $borrower,
            requiredSettlementAmount: $stillNeeded,
            obligationType: PendingSaleProceeds::OBLIGATION_BRIDGE,
            recall: $recall,
            bridgeLoan: $bridge,
            soldAt: $asOf,
            actualProceedsHaircutRatio: $actualProceedsHaircutRatio,
        );

        return [
            'bridge' => $bridge->fresh(),
            'liquidated' => count($result['sales']) > 0,
            'liquidation' => $result,
        ];
    }

    /**
     * Process due proceeds then continue liquidation for open obligations.
     *
     * @return array<string, mixed>
     */
    public function processSettlements(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $applied = $this->application->processDue($asOf);

        $recallResults = [];
        $recalls = CapitalRecall::query()
            ->whereIn('state', [
                CapitalRecall::STATE_PENDING_HELD,
                CapitalRecall::STATE_LIQUIDATION,
                CapitalRecall::STATE_SETTLEMENT,
            ])
            ->where('outstanding_recall_amount', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($recalls as $recall) {
            $profile = PortfolioProfile::query()->find($recall->profile_id);
            if (! $profile) {
                continue;
            }
            $recallResults[] = $this->continueRecallFulfilment($profile, $recall, $asOf);
        }

        $bridgeResults = [];
        $bridges = RecallBridgeLoan::query()
            ->whereIn('status', [
                RecallBridgeLoan::STATUS_OUTSTANDING,
                RecallBridgeLoan::STATUS_PARTIALLY_RETURNED,
            ])
            ->where('outstanding', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($bridges as $bridge) {
            $profile = PortfolioProfile::query()->find($bridge->profile_id);
            if (! $profile) {
                continue;
            }
            $bridgeResults[] = $this->liquidateToRepayBridge($profile, $bridge, $asOf);
        }

        return [
            'proceeds' => $applied,
            'recalls' => $recallResults,
            'bridges' => $bridgeResults,
        ];
    }
}
