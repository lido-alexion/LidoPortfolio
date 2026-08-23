<?php

namespace App\Services\Lending;

use App\Models\CapitalRecall;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use App\Services\StockQuoteService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use Carbon\CarbonInterface;

/**
 * DEP-CAPITAL-PRIORITY — own → recall → borrow; execute at actual available.
 *
 * Phase 1 foundation: computes funding plan and may initiate/settle recalls.
 * Does not change Manual/Semi/Auto trade execution modes.
 * Does not leave a recommendation open waiting for an unavailable remainder.
 */
final class CapitalResolutionService
{
    public function __construct(
        protected PortfolioCapitalAccountingService $accounting,
        protected RecallEligibilityService $eligibility,
        protected RecallService $recalls,
        protected RecallImmediateSettlementService $immediateSettlement,
        protected StockQuoteService $quotes,
        protected RecallNotificationService $notifications,
    ) {}

    /**
     * @param  array{
     *   bridge_lender?: TradingStrategy|null,
     *   as_of?: CarbonInterface|null,
     *   borrower_own_cash_overrides?: array<int, float>,
     *   liquidatable_stock_overrides?: array<int, float>
     * }  $options
     * @return array{
     *   required_amount: float,
     *   own_available: float,
     *   own_used: float,
     *   recalled_amount: float,
     *   borrow_shortfall: float,
     *   actual_available: float,
     *   close_at_actual: true,
     *   hold_for_remainder: false,
     *   recalls: list<array<string, mixed>>
     * }
     */
    public function resolveForStrategy(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        float $requiredAmount,
        array $options = [],
    ): array {
        $requiredAmount = round(max(0.0, $requiredAmount), 4);
        $asOf = $options['as_of'] ?? null;
        $bridgeLender = $options['bridge_lender'] ?? null;

        $ownAvailable = array_key_exists('own_available_override', $options)
            ? round(max(0.0, (float) $options['own_available_override']), 4)
            : $this->strategyAvailableCapital($profile, $strategy);
        $ownUsed = round(min($ownAvailable, $requiredAmount), 4);
        $shortfall = round(max(0.0, $requiredAmount - $ownUsed), 4);
        $recalled = 0.0;
        $recallResults = [];

        if ($shortfall > 0.0001) {
            $loans = $this->eligibility->eligibleLoansForLender($profile, $strategy, $asOf);
            foreach ($loans as $loan) {
                if ($shortfall <= 0.0001) {
                    break;
                }
                if ($this->eligibility->hasActiveRecall($profile)) {
                    break;
                }
                if (! $this->eligibility->canInitiateRecall($profile, $loan, $asOf)) {
                    continue;
                }

                $recall = $this->recalls->requestForShortfall($profile, $loan, $shortfall, $asOf);
                if ($recall === null) {
                    continue;
                }

                $borrowerId = (int) $recall->borrower_strategy_id;
                $borrowerCash = $options['borrower_own_cash_overrides'][$borrowerId]
                    ?? $this->strategyAvailableCapitalById($profile, $borrowerId);
                $stockValue = $options['liquidatable_stock_overrides'][$borrowerId]
                    ?? $this->liquidatableStockValue($profile, $borrowerId);

                $applied = $this->immediateSettlement->apply(
                    $profile,
                    $recall,
                    $borrowerCash,
                    $stockValue,
                    $bridgeLender,
                );

                /** @var CapitalRecall $recallRow */
                $recallRow = $applied['recall'];
                $settled = (float) $recallRow->settled_amount;
                // Capital returned to lender only on immediate settlement path
                if ($applied['evaluation']['allows_immediate']) {
                    $recalled = round($recalled + $settled, 4);
                    $shortfall = round(max(0.0, $shortfall - $settled), 4);
                }

                $recallResults[] = [
                    'recall_id' => $recallRow->id,
                    'loan_id' => $recallRow->loan_id,
                    'state' => $recallRow->state,
                    'recall_amount' => (float) $recallRow->recall_amount,
                    'settled_amount' => $settled,
                    'allows_immediate' => (bool) $applied['evaluation']['allows_immediate'],
                ];

                // One active recall at a time — stop after first initiation
                break;
            }
        }

        $actual = round($ownUsed + $recalled, 4);
        // Borrow path is acknowledged as remaining shortfall for later normal lending;
        // Phase 1 does not auto-create normal loans here.
        $borrowShortfall = round(max(0.0, $requiredAmount - $actual), 4);

        $result = [
            'required_amount' => $requiredAmount,
            'own_available' => round($ownAvailable, 4),
            'own_used' => $ownUsed,
            'recalled_amount' => $recalled,
            'borrow_shortfall' => $borrowShortfall,
            'actual_available' => $actual,
            'close_at_actual' => true,
            'hold_for_remainder' => false,
            'recalls' => $recallResults,
        ];

        if ($borrowShortfall >= 1.0 && $actual > 0.0001) {
            $this->notifications->partialCapitalResolution($profile, $requiredAmount, $actual);
        }

        return $result;
    }

    public function strategyAvailableCapital(PortfolioProfile $profile, TradingStrategy $strategy): float
    {
        return $this->strategyAvailableCapitalById($profile, (int) $strategy->id);
    }

    public function strategyAvailableCapitalById(PortfolioProfile $profile, int $strategyId): float
    {
        $snapshot = $this->accounting->snapshot($profile);
        foreach ($snapshot['strategies'] as $row) {
            if ((int) $row['strategy_id'] === $strategyId) {
                return (float) $row['strategy_available_capital'];
            }
        }

        return 0.0;
    }

    public function liquidatableStockValue(PortfolioProfile $profile, int $strategyId): float
    {
        $holdings = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('strategy_id', $strategyId)
            ->where('quantity', '>', 0)
            ->get();

        $sum = 0.0;
        foreach ($holdings as $holding) {
            $sum += (float) $holding->quantity * $this->quotes->latestClose((int) $holding->stock_id);
        }

        return round($sum, 4);
    }
}
