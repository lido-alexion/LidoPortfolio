<?php

namespace App\Services\Lending;

use App\Models\CapitalRecall;
use App\Models\Holding;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\Stock;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Services\TransactionWriteService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Weakest-position liquidation for recall / bridge funding gaps (V3 §17.1).
 * Sells do not release broker cash immediately — PendingSaleProceeds tracks delay.
 */
final class RecallLiquidationService
{
    public function __construct(
        protected WeakestPositionRanker $ranker,
        protected SaleBufferCalculator $buffers,
        protected SaleProceedsAvailabilityService $proceeds,
        protected TransactionWriteService $writes,
        protected RecallService $recalls,
        protected RecallNotificationService $notifications,
    ) {}

    /**
     * Liquidate borrower positions to cover a required settlement amount.
     * Does nothing (no unnecessary liquidation) when required ≤ 0.
     *
     * @return array{
     *   required_settlement_amount: float,
     *   target_liquidation_value: float,
     *   sale_buffer_amount: float,
     *   expected_proceeds: float,
     *   sales: list<array<string, mixed>>,
     *   pending_proceeds: list<PendingSaleProceeds>,
     *   remaining_unfunded: float
     * }
     */
    public function liquidateForObligation(
        PortfolioProfile $profile,
        TradingStrategy $borrower,
        float $requiredSettlementAmount,
        string $obligationType,
        ?CapitalRecall $recall = null,
        ?RecallBridgeLoan $bridgeLoan = null,
        ?CarbonInterface $soldAt = null,
        ?float $actualProceedsHaircutRatio = null,
    ): array {
        $requiredSettlementAmount = round(max(0.0, $requiredSettlementAmount), 4);
        $soldAt = $soldAt ? Carbon::parse($soldAt) : now();

        if ($requiredSettlementAmount <= 0.0001) {
            return [
                'required_settlement_amount' => 0.0,
                'target_liquidation_value' => 0.0,
                'sale_buffer_amount' => 0.0,
                'expected_proceeds' => 0.0,
                'sales' => [],
                'pending_proceeds' => [],
                'remaining_unfunded' => 0.0,
            ];
        }

        if (! in_array($obligationType, [
            PendingSaleProceeds::OBLIGATION_RECALL,
            PendingSaleProceeds::OBLIGATION_BRIDGE,
        ], true)) {
            throw ValidationException::withMessages([
                'obligation_type' => ['Obligation type must be recall or bridge.'],
            ]);
        }

        $sized = $this->buffers->size($requiredSettlementAmount);
        $stillNeeded = $sized['target_liquidation_value'];
        $ranked = $this->ranker->rankBorrowerPositions($profile, $borrower, $soldAt);

        $sales = [];
        $pendingRows = [];
        $expectedTotal = 0.0;

        return DB::transaction(function () use (
            $profile,
            $borrower,
            $ranked,
            $stillNeeded,
            $sized,
            $obligationType,
            $recall,
            $bridgeLoan,
            $soldAt,
            $actualProceedsHaircutRatio,
            &$sales,
            &$pendingRows,
            &$expectedTotal,
        ) {
            if ($recall !== null && $recall->state === CapitalRecall::STATE_PENDING_HELD) {
                $this->recalls->markLiquidation($recall);
            } elseif ($recall !== null && in_array($recall->state, [
                CapitalRecall::STATE_REQUESTED,
                CapitalRecall::STATE_IMMEDIATE_SETTLEMENT,
            ], true)) {
                $this->recalls->markLiquidation($recall);
            }

            foreach ($ranked as $row) {
                if ($stillNeeded <= 0.0001) {
                    break;
                }

                /** @var Holding $holding */
                $holding = $row['holding'];
                $price = (float) $row['current_price'];
                $qtyAvailable = (float) $holding->quantity;
                if ($price <= 0 || $qtyAvailable <= 0) {
                    continue;
                }

                $sliceValue = min($stillNeeded, (float) $row['market_value']);
                $qty = floor(($sliceValue / $price) * 10000) / 10000; // 4dp
                if ($qty <= 0) {
                    // Need at least one share if price ≤ remaining need
                    if ($price <= $stillNeeded + 0.0001 && $qtyAvailable >= 1) {
                        $qty = 1.0;
                    } else {
                        continue;
                    }
                }
                $qty = min($qty, $qtyAvailable);
                $expected = round($qty * $price, 4);
                if ($expected <= 0) {
                    continue;
                }

                $actual = $expected;
                if ($actualProceedsHaircutRatio !== null && $actualProceedsHaircutRatio > 0) {
                    $actual = round($expected * (1.0 - $actualProceedsHaircutRatio), 4);
                }

                $stock = Stock::query()->findOrFail((int) $holding->stock_id);
                $tx = $this->writes->create(
                    $profile,
                    $stock,
                    [
                        'type' => 'sell',
                        'quantity' => $qty,
                        'price' => $price,
                        'fees' => 0,
                        'transaction_date' => $soldAt->toDateString(),
                        'notes' => 'Recall liquidation — Proceeds from Stock Sale (pending settlement)',
                        'source' => Transaction::SOURCE_OTHER,
                        'owner_key' => $holding->owner_key ?: Holding::ownerKeyFor((int) $borrower->id),
                    ],
                    softFailSnapshots: true,
                    user: null,
                    applyCash: false,
                );

                $pending = $this->proceeds->scheduleForObligation(
                    profile: $profile,
                    strategy: $borrower,
                    actualAmount: $actual,
                    expectedAmount: $expected,
                    obligationType: $obligationType,
                    soldAt: $soldAt,
                    capitalRecallId: $recall?->id,
                    recallBridgeLoanId: $bridgeLoan?->id,
                    transactionId: (int) $tx->id,
                    requiredSettlementAmount: $sized['required_settlement_amount'],
                    targetLiquidationValue: $sized['target_liquidation_value'],
                    saleBufferAmount: $sized['sale_buffer_amount'],
                );

                $sales[] = [
                    'transaction_id' => $tx->id,
                    'stock_id' => $stock->id,
                    'quantity' => $qty,
                    'price' => $price,
                    'expected_proceeds' => $expected,
                    'actual_proceeds' => $actual,
                ];
                $pendingRows[] = $pending;
                $this->notifications->saleInitiated($profile, $pending);
                $expectedTotal = round($expectedTotal + $expected, 4);
                $stillNeeded = round(max(0.0, $stillNeeded - $expected), 4);
            }

            $actualSum = round(array_sum(array_map(fn (PendingSaleProceeds $p) => (float) $p->amount, $pendingRows)), 4);
            $remainingUnfunded = round(max(0.0, $sized['required_settlement_amount'] - $actualSum), 4);

            return [
                'required_settlement_amount' => $sized['required_settlement_amount'],
                'target_liquidation_value' => $sized['target_liquidation_value'],
                'sale_buffer_amount' => $sized['sale_buffer_amount'],
                'expected_proceeds' => $expectedTotal,
                'sales' => $sales,
                'pending_proceeds' => $pendingRows,
                'remaining_unfunded' => $remainingUnfunded,
            ];
        });
    }
}
