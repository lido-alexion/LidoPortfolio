<?php

namespace App\Services\Lending;

use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEP-SALE-PROCEEDS — schedule, release, and track Proceeds from Stock Sale.
 * Sale execution ≠ immediately available broker cash (~1 calendar day).
 */
final class SaleProceedsAvailabilityService
{
    public const SETTLEMENT_DELAY_DAYS = 1;

    public function __construct(
        protected SpecialCashMovementService $specialCash,
    ) {}

    public function schedule(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        float $amount,
        ?CarbonInterface $soldAt = null,
        ?int $capitalRecallId = null,
    ): PendingSaleProceeds {
        return $this->scheduleForObligation(
            profile: $profile,
            strategy: $strategy,
            actualAmount: $amount,
            expectedAmount: $amount,
            obligationType: PendingSaleProceeds::OBLIGATION_RECALL,
            soldAt: $soldAt,
            capitalRecallId: $capitalRecallId,
        );
    }

    public function scheduleForObligation(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        float $actualAmount,
        float $expectedAmount,
        string $obligationType,
        ?CarbonInterface $soldAt = null,
        ?int $capitalRecallId = null,
        ?int $recallBridgeLoanId = null,
        ?int $transactionId = null,
        ?float $requiredSettlementAmount = null,
        ?float $targetLiquidationValue = null,
        ?float $saleBufferAmount = null,
    ): PendingSaleProceeds {
        $soldAt = $soldAt ? Carbon::parse($soldAt) : now();
        $availableAt = $soldAt->copy()->addDays(self::SETTLEMENT_DELAY_DAYS);

        return PendingSaleProceeds::query()->create([
            'profile_id' => $profile->id,
            'strategy_id' => $strategy->id,
            'capital_recall_id' => $capitalRecallId,
            'obligation_type' => $obligationType,
            'recall_bridge_loan_id' => $recallBridgeLoanId,
            'transaction_id' => $transactionId,
            'amount' => round($actualAmount, 4),
            'expected_amount' => round($expectedAmount, 4),
            'required_settlement_amount' => $requiredSettlementAmount !== null ? round($requiredSettlementAmount, 4) : null,
            'target_liquidation_value' => $targetLiquidationValue !== null ? round($targetLiquidationValue, 4) : null,
            'sale_buffer_amount' => $saleBufferAmount !== null ? round($saleBufferAmount, 4) : null,
            'sold_at' => $soldAt,
            'available_at' => $availableAt,
            'status' => PendingSaleProceeds::STATUS_PENDING,
        ]);
    }

    public function isPhysicallyAvailable(PendingSaleProceeds $row, ?CarbonInterface $asOf = null): bool
    {
        if ($row->status === PendingSaleProceeds::STATUS_APPLIED) {
            return false;
        }
        $asOf = $asOf ? Carbon::parse($asOf) : now();

        return $asOf->greaterThanOrEqualTo(Carbon::parse($row->available_at));
    }

    public function refreshStatus(PendingSaleProceeds $row, ?CarbonInterface $asOf = null): PendingSaleProceeds
    {
        if ($row->status === PendingSaleProceeds::STATUS_APPLIED) {
            return $row;
        }
        if ($this->isPhysicallyAvailable($row, $asOf)) {
            $row->forceFill(['status' => PendingSaleProceeds::STATUS_AVAILABLE])->save();
        }

        return $row->fresh();
    }

    /**
     * Release sell cash into the physical pool once (idempotent).
     * V4-SPEC-004: posts signed RECALL or BRIDGE (not deposit). SELL was recorded with applyCash=false.
     */
    public function releaseCashIfDue(PendingSaleProceeds $row, ?CarbonInterface $asOf = null): PendingSaleProceeds
    {
        $row = $this->refreshStatus($row, $asOf);
        if ($row->status !== PendingSaleProceeds::STATUS_AVAILABLE) {
            return $row;
        }
        if ($row->cash_released_at !== null) {
            return $row;
        }

        return DB::transaction(function () use ($row) {
            /** @var PendingSaleProceeds $locked */
            $locked = PendingSaleProceeds::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->cash_released_at !== null) {
                return $locked;
            }

            $profile = PortfolioProfile::query()->findOrFail($locked->profile_id);
            $this->specialCash->postProceedsRelease($profile, $locked);

            $locked->forceFill(['cash_released_at' => now()])->save();

            return $locked->fresh();
        });
    }

    public function markApplied(PendingSaleProceeds $row, ?CarbonInterface $at = null): PendingSaleProceeds
    {
        $row->forceFill([
            'status' => PendingSaleProceeds::STATUS_APPLIED,
            'applied_at' => $at ? Carbon::parse($at) : now(),
        ])->save();

        return $row->fresh();
    }

    /**
     * Revise actual proceeds downward before application (never above expected).
     */
    public function setActualProceeds(PendingSaleProceeds $row, float $actualAmount): PendingSaleProceeds
    {
        if ($row->status === PendingSaleProceeds::STATUS_APPLIED) {
            return $row;
        }
        $actualAmount = round(max(0.0, $actualAmount), 4);
        $row->forceFill(['amount' => $actualAmount])->save();

        return $row->fresh();
    }

    public function availableAmount(PortfolioProfile $profile, TradingStrategy $strategy, ?CarbonInterface $asOf = null): float
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $rows = PendingSaleProceeds::query()
            ->where('profile_id', $profile->id)
            ->where('strategy_id', $strategy->id)
            ->whereIn('status', [
                PendingSaleProceeds::STATUS_PENDING,
                PendingSaleProceeds::STATUS_AVAILABLE,
            ])
            ->get();

        $sum = 0.0;
        foreach ($rows as $row) {
            $row = $this->refreshStatus($row, $asOf);
            if ($row->status === PendingSaleProceeds::STATUS_AVAILABLE) {
                $sum += (float) $row->amount;
            }
        }

        return round($sum, 4);
    }

    /**
     * @return \Illuminate\Support\Collection<int, PendingSaleProceeds>
     */
    public function dueForRelease(?CarbonInterface $asOf = null)
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();

        return PendingSaleProceeds::query()
            ->whereIn('status', [
                PendingSaleProceeds::STATUS_PENDING,
                PendingSaleProceeds::STATUS_AVAILABLE,
            ])
            ->where('available_at', '<=', $asOf)
            ->whereNull('applied_at')
            ->orderBy('id')
            ->get();
    }
}
