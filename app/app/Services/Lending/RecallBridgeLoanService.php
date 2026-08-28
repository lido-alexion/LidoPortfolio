<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingStrategy;
use App\Services\StockQuoteService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Create / repay Recall Bridge Loans (DEP-RECALL-BRIDGE).
 * Cannot fund investments. Cannot be recalled. Any repayment ≤ outstanding.
 * create() enforces cushion + lender capacity (callers cannot bypass).
 */
final class RecallBridgeLoanService
{
    public function __construct(
        protected RecallBridgeEligibilityCalculator $eligibility,
        protected RecallNotificationService $notifications,
        protected PortfolioCapitalAccountingService $accounting,
        protected StockQuoteService $quotes,
        protected SpecialCashMovementService $specialCash,
    ) {}

    /**
     * @return array<string, float>
     */
    public function evaluateEligibility(
        float $recallAmount,
        float $borrowerOwnCash,
        float $liquidatableStockValue,
    ): array {
        return $this->eligibility->evaluate($recallAmount, $borrowerOwnCash, $liquidatableStockValue);
    }

    /**
     * @param  array{
     *   borrower_own_cash?: float,
     *   liquidatable_stock_value?: float,
     *   lender_available_override?: float
     * }  $context
     */
    public function create(
        PortfolioProfile $profile,
        CapitalRecall $recall,
        TradingStrategy $bridgeLender,
        float $amount,
        array $context = [],
    ): RecallBridgeLoan {
        $amount = round($amount, 4);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Recall Bridge Loan amount must be greater than 0.'],
            ]);
        }
        if ((int) $bridgeLender->id === (int) $recall->borrower_strategy_id) {
            throw ValidationException::withMessages([
                'lender' => ['Bridge lender must differ from the recall borrower.'],
            ]);
        }
        if ((int) $bridgeLender->id === (int) $recall->lender_strategy_id) {
            throw ValidationException::withMessages([
                'lender' => ['Bridge lender must differ from the recall lender.'],
            ]);
        }

        // Idempotent: same recall + lender + principal already outstanding.
        $existing = RecallBridgeLoan::query()
            ->where('capital_recall_id', $recall->id)
            ->where('lender_strategy_id', $bridgeLender->id)
            ->where('outstanding', '>', 0)
            ->orderBy('id')
            ->first();
        if ($existing !== null && abs((float) $existing->principal - $amount) <= 0.0001) {
            $this->specialCash->postBridgeDisbursement($profile, $existing);

            return $existing;
        }
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'bridge' => ['An outstanding Recall Bridge Loan already exists for this recall and lender.'],
            ]);
        }

        $borrowerCash = array_key_exists('borrower_own_cash', $context)
            ? round(max(0.0, (float) $context['borrower_own_cash']), 4)
            : $this->strategyAvailableCapital($profile, (int) $recall->borrower_strategy_id);
        $stockValue = array_key_exists('liquidatable_stock_value', $context)
            ? round(max(0.0, (float) $context['liquidatable_stock_value']), 4)
            : $this->liquidatableStockValue($profile, (int) $recall->borrower_strategy_id);

        $eval = $this->eligibility->evaluate(
            (float) $recall->outstanding_recall_amount > 0
                ? (float) $recall->outstanding_recall_amount
                : (float) $recall->recall_amount,
            $borrowerCash,
            $stockValue,
        );
        if ($amount > (float) $eval['eligible_bridge'] + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Recall Bridge Loan exceeds eligible amount under the 10% stock cushion rule.',
                ],
            ]);
        }

        $lenderAvailable = array_key_exists('lender_available_override', $context)
            ? round(max(0.0, (float) $context['lender_available_override']), 4)
            : $this->strategyAvailableCapital($profile, (int) $bridgeLender->id);
        if ($amount > $lenderAvailable + 0.0001) {
            throw ValidationException::withMessages([
                'lender' => ['Bridge lender does not have sufficient available capital.'],
            ]);
        }

        $loan = RecallBridgeLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_recall_id' => $recall->id,
            'borrower_strategy_id' => $recall->borrower_strategy_id,
            'lender_strategy_id' => $bridgeLender->id,
            'principal' => $amount,
            'outstanding' => $amount,
            'committed_at' => now(),
            'status' => RecallBridgeLoan::STATUS_OUTSTANDING,
        ]);
        $this->specialCash->postBridgeDisbursement($profile, $loan);
        $this->notifications->bridgeCreated($profile, $loan);

        return $loan;
    }

    public function repay(RecallBridgeLoan $loan, float $amount, bool $postCash = true): RecallBridgeLoan
    {
        return DB::transaction(function () use ($loan, $amount, $postCash) {
            /** @var RecallBridgeLoan $locked */
            $locked = RecallBridgeLoan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $amount = round($amount, 4);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Bridge repayment must be greater than 0.'],
                ]);
            }
            $outstanding = round((float) $locked->outstanding, 4);
            if ($locked->status === RecallBridgeLoan::STATUS_RETURNED || $outstanding <= 0) {
                throw ValidationException::withMessages([
                    'loan' => ['This Recall Bridge Loan has already been fully returned.'],
                ]);
            }
            if ($amount > $outstanding + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['Bridge repayment cannot exceed outstanding.'],
                ]);
            }

            $newOutstanding = round(max(0.0, $outstanding - $amount), 4);
            if ($newOutstanding <= 0.0001) {
                $newOutstanding = 0.0;
            }

            $locked->forceFill([
                'outstanding' => $newOutstanding,
                'status' => $newOutstanding <= 0.0
                    ? RecallBridgeLoan::STATUS_RETURNED
                    : RecallBridgeLoan::STATUS_PARTIALLY_RETURNED,
            ])->save();

            $fresh = $locked->fresh();
            $profile = PortfolioProfile::query()->find($fresh->profile_id);
            if ($profile && $postCash) {
                $this->specialCash->postBridgeRepayment($profile, $fresh, $amount, $newOutstanding);
            }
            if ($profile) {
                $this->notifications->bridgeRepaid($profile, $fresh, $amount);
            }

            return $fresh;
        });
    }

    /** Bridge loans cannot themselves be recalled. */
    public function recall(RecallBridgeLoan $loan): never
    {
        throw new LogicException('A Recall Bridge Loan cannot itself be recalled.');
    }

    /** Bridge loans cannot fund investments. */
    public function assertNotUsedForInvestment(): void
    {
        // Domain guard used by callers — bridge capital is recall-fulfil only.
    }

    protected function strategyAvailableCapital(PortfolioProfile $profile, int $strategyId): float
    {
        foreach ($this->accounting->snapshot($profile)['strategies'] as $row) {
            if ((int) $row['strategy_id'] === $strategyId) {
                return (float) $row['strategy_available_capital'];
            }
        }

        return 0.0;
    }

    protected function liquidatableStockValue(PortfolioProfile $profile, int $strategyId): float
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
