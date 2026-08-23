<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\PortfolioProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Recall request + lifecycle transitions (v0.28 §6.6–§6.14).
 * Recalls cannot be cancelled. One active recall portfolio-wide.
 */
final class RecallService
{
    public function __construct(
        protected RecallEligibilityService $eligibility,
        protected RecallAmountCalculator $amounts,
        protected RecallNotificationService $notifications,
    ) {}

    public function requestFull(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        ?CarbonInterface $asOf = null,
    ): CapitalRecall {
        $sized = $this->amounts->full((float) $loan->outstanding);

        return $this->request($profile, $loan, $sized['kind'], $sized['amount'], $asOf);
    }

    public function requestPartial(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        float $amount,
        ?CarbonInterface $asOf = null,
    ): CapitalRecall {
        $sized = $this->amounts->partial($amount, (float) $loan->outstanding);

        return $this->request($profile, $loan, $sized['kind'], $sized['amount'], $asOf);
    }

    /**
     * Automated capital-resolution sizing for a shortfall against one loan.
     */
    public function requestForShortfall(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        float $shortfall,
        ?CarbonInterface $asOf = null,
    ): ?CapitalRecall {
        $sized = $this->amounts->forShortfall($shortfall, (float) $loan->outstanding);
        if ($sized === null) {
            return null;
        }

        return $this->request($profile, $loan, $sized['kind'], $sized['amount'], $asOf);
    }

    public function request(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        string $kind,
        float $amount,
        ?CarbonInterface $asOf = null,
    ): CapitalRecall {
        $asOf = $asOf ? Carbon::parse($asOf) : now();

        if ((int) $loan->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'loan' => ['Loan does not belong to this portfolio.'],
            ]);
        }

        if ($kind === RecallAmountCalculator::KIND_FULL) {
            $this->amounts->full((float) $loan->outstanding);
            $amount = round((float) $loan->outstanding, 4);
        } else {
            $this->amounts->partial($amount, (float) $loan->outstanding);
            $amount = round($amount, 4);
        }

        if (! $this->eligibility->canInitiateRecall($profile, $loan, $asOf)) {
            throw ValidationException::withMessages([
                'recall' => ['Recall cannot be initiated (eligibility, active recall, or follow-up cooldown).'],
            ]);
        }

        return tap(DB::transaction(function () use ($profile, $loan, $kind, $amount, $asOf) {
            if ($this->eligibility->hasActiveRecall($profile->fresh())) {
                throw ValidationException::withMessages([
                    'recall' => ['Only one active recall is allowed at a time.'],
                ]);
            }

            return CapitalRecall::query()->create([
                'profile_id' => $profile->id,
                'loan_id' => $loan->id,
                'lender_strategy_id' => $loan->lender_strategy_id,
                'borrower_strategy_id' => $loan->borrower_strategy_id,
                'kind' => $kind,
                'recall_amount' => $amount,
                'outstanding_recall_amount' => $amount,
                'settled_amount' => 0,
                'state' => CapitalRecall::STATE_REQUESTED,
                'requested_at' => $asOf,
            ]);
        }), function (CapitalRecall $recall) use ($profile) {
            $this->notifications->recallRequested($profile, $recall);
        });
    }

    /**
     * Create a recall and run the same immediate-settlement + fulfilment workflow
     * used by automated capital resolution (does not leave recalls stuck at requested).
     *
     * @param  array{
     *   as_of?: CarbonInterface|null,
     *   borrower_own_cash?: float|null,
     *   liquidatable_stock_value?: float|null,
     *   bridge_lender?: \App\Models\TradingStrategy|null
     * }  $options
     * @return array{recall: CapitalRecall, settlement: array<string, mixed>}
     */
    public function requestAndProcess(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        string $kind,
        float $amount = 0.0,
        array $options = [],
    ): array {
        $asOf = $options['as_of'] ?? null;
        if ($kind === RecallAmountCalculator::KIND_FULL) {
            $recall = $this->requestFull($profile, $loan, $asOf);
        } else {
            $recall = $this->requestPartial($profile, $loan, $amount, $asOf);
        }

        $settlement = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            array_key_exists('borrower_own_cash', $options) && $options['borrower_own_cash'] !== null
                ? (float) $options['borrower_own_cash']
                : app(CapitalResolutionService::class)->strategyAvailableCapitalById(
                    $profile,
                    (int) $recall->borrower_strategy_id,
                ),
            array_key_exists('liquidatable_stock_value', $options) && $options['liquidatable_stock_value'] !== null
                ? (float) $options['liquidatable_stock_value']
                : app(CapitalResolutionService::class)->liquidatableStockValue(
                    $profile,
                    (int) $recall->borrower_strategy_id,
                ),
            $options['bridge_lender'] ?? null,
        );

        return [
            'recall' => $settlement['recall'],
            'settlement' => $settlement,
        ];
    }

    public function cancel(CapitalRecall $recall): never
    {
        throw new LogicException('A recall cannot be cancelled.');
    }

    public function markImmediateSettlement(CapitalRecall $recall): CapitalRecall
    {
        return $this->transition($recall, CapitalRecall::STATE_IMMEDIATE_SETTLEMENT);
    }

    public function markPendingHeld(CapitalRecall $recall): CapitalRecall
    {
        $recall = $this->transition($recall, CapitalRecall::STATE_PENDING_HELD);
        $recall->forceFill(['pending_held_at' => now()])->save();
        $profile = PortfolioProfile::query()->find($recall->profile_id);
        if ($profile) {
            $this->notifications->recallPendingHeld($profile, $recall);
        }

        return $recall->fresh();
    }

    public function markLiquidation(CapitalRecall $recall): CapitalRecall
    {
        return $this->transition($recall, CapitalRecall::STATE_LIQUIDATION);
    }

    public function markSettlement(CapitalRecall $recall): CapitalRecall
    {
        return $this->transition($recall, CapitalRecall::STATE_SETTLEMENT);
    }

    public function markCompleted(CapitalRecall $recall, ?CarbonInterface $at = null): CapitalRecall
    {
        $at = $at ? Carbon::parse($at) : now();
        if ($recall->state === CapitalRecall::STATE_COMPLETED) {
            return $recall;
        }
        if (! $recall->isActive() && $recall->state !== CapitalRecall::STATE_REQUESTED) {
            // Allow complete from active states and immediate/settlement paths.
        }

        $recall->forceFill([
            'state' => CapitalRecall::STATE_COMPLETED,
            'completed_at' => $at,
            'outstanding_recall_amount' => 0,
        ])->save();

        return $recall->fresh();
    }

    public function applySettlementAmount(CapitalRecall $recall, float $amount): CapitalRecall
    {
        $amount = round($amount, 4);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Settlement amount must be greater than 0.'],
            ]);
        }

        return tap(DB::transaction(function () use ($recall, $amount) {
            /** @var CapitalRecall $locked */
            $locked = CapitalRecall::query()->whereKey($recall->id)->lockForUpdate()->firstOrFail();
            $outstanding = round((float) $locked->outstanding_recall_amount, 4);
            if ($amount > $outstanding + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['Settlement cannot exceed outstanding recall amount.'],
                ]);
            }

            $newOutstanding = round(max(0.0, $outstanding - $amount), 4);
            $settled = round((float) $locked->settled_amount + $amount, 4);
            $locked->forceFill([
                'outstanding_recall_amount' => $newOutstanding <= 0.0001 ? 0.0 : $newOutstanding,
                'settled_amount' => $settled,
            ])->save();

            if ($newOutstanding <= 0.0001) {
                return $this->markCompleted($locked->fresh());
            }

            return $locked->fresh();
        }), function (CapitalRecall $updated) use ($amount) {
            $profile = PortfolioProfile::query()->find($updated->profile_id);
            if ($profile) {
                $this->notifications->recallSettlementBatch($profile, $updated, $amount);
            }
        });
    }

    private function transition(CapitalRecall $recall, string $to): CapitalRecall
    {
        if ($recall->state === CapitalRecall::STATE_COMPLETED) {
            throw ValidationException::withMessages([
                'state' => ['Completed recalls cannot change state.'],
            ]);
        }

        $recall->forceFill(['state' => $to])->save();

        return $recall->fresh();
    }
}
