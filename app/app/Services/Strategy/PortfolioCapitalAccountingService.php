<?php

namespace App\Services\Strategy;

use App\Models\CapitalLoan;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\CashManagementService;
use App\Services\ProfileSettingsService;
use App\Services\StockQuoteService;
use App\Support\FloorToRupee5000;
use App\Support\NearestIntegerRupee;
use Illuminate\Validation\ValidationException;

/**
 * V3 Workstream 2 + WS4 Step 2 — portfolio cash / strategy capital accounting
 * (OD-19 / OD-20 / OD-21 / OD-24) plus outstanding lent/borrowed and available_for_lending.
 * Physical cash remains one portfolio pool. Does not create strategy bank accounts.
 * Does not implement lending workflow, approval, or recall.
 */
class PortfolioCapitalAccountingService
{
    public const RESERVE_PCT_SETTING = 'portfolio_cash_reserve_pct';

    public function __construct(
        protected CashManagementService $cash,
        protected ProfileSettingsService $profileSettings,
        protected StockQuoteService $quotes,
    ) {}

    /**
     * Configured OD-19 percentage. Unset is not a frozen default — treated as 0 (no reserve configured).
     */
    public function portfolioCashReservePct(PortfolioProfile $profile): float
    {
        $raw = $this->profileSettings->get($profile, self::RESERVE_PCT_SETTING, '');
        if ($raw === null || trim((string) $raw) === '') {
            return 0.0;
        }

        return max(0.0, (float) $raw);
    }

    /**
     * @param  list<array{strategy_id:int, allocation_pct:float|int|string}>  $rows
     */
    public function updateEnabledAllocations(PortfolioProfile $profile, array $rows): void
    {
        $enabled = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        $enabledIds = $enabled->pluck('id')->map(fn ($id) => (int) $id)->all();
        $incoming = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['strategy_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $incoming[$id] = (float) ($row['allocation_pct'] ?? 0);
        }

        sort($enabledIds);
        $incomingIds = array_keys($incoming);
        sort($incomingIds);
        if ($enabledIds !== $incomingIds) {
            throw ValidationException::withMessages([
                'allocations' => ['Allocation update must include every enabled strategy and no others.'],
            ]);
        }

        foreach ($incoming as $pct) {
            if ($pct < 0) {
                throw ValidationException::withMessages([
                    'allocations' => ['Each allocation_pct must be greater than or equal to 0.'],
                ]);
            }
        }

        $sum = round(array_sum($incoming), 4);
        if (abs($sum - 100.0) > 0.01) {
            throw ValidationException::withMessages([
                'allocations' => ['Enabled strategy allocation percentages must sum to 100. Current sum: '.$sum.'.'],
            ]);
        }

        foreach ($enabled as $strategy) {
            $strategy->forceFill([
                'allocation_pct' => $incoming[(int) $strategy->id],
            ])->save();
        }
    }

    public function updatePortfolioCashReservePct(PortfolioProfile $profile, float $pct): void
    {
        if ($pct < 0 || $pct > 100) {
            throw ValidationException::withMessages([
                'portfolio_cash_reserve_pct' => ['Portfolio cash reserve % must be between 0 and 100.'],
            ]);
        }

        $this->profileSettings->set($profile, self::RESERVE_PCT_SETTING, (string) $pct);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(PortfolioProfile $profile): array
    {
        $totalCash = $this->cash->balance($profile);
        $pendingReserved = $this->cash->reservedCash($profile);
        $availablePhysical = round(max(0.0, $totalCash - $pendingReserved), 4);

        $holdings = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->get();

        $totalInvested = 0.0;
        $totalNotional = 0.0;
        $unmanagedMv = 0.0;
        $strategyOwnedMv = [];

        foreach ($holdings as $holding) {
            $mv = (float) $holding->quantity * $this->quotes->latestClose((int) $holding->stock_id);
            $invested = (float) $holding->invested_amount;
            $totalInvested += $invested;
            $totalNotional += $mv;
            if ($holding->isUnmanaged()) {
                $unmanagedMv += $mv;
            } else {
                $sid = (int) $holding->strategy_id;
                $strategyOwnedMv[$sid] = ($strategyOwnedMv[$sid] ?? 0.0) + $mv;
            }
        }

        $reservePct = $this->portfolioCashReservePct($profile);
        $reserveBase = max($totalInvested, $totalNotional);
        $requiredReserve = round($reserveBase * ($reservePct / 100.0), 4);
        $reserveShortfall = round(max(0.0, $requiredReserve - $totalCash), 4);
        $reserveShortfallExists = $totalCash + 0.0001 < $requiredReserve;

        $investableCash = $totalCash - $requiredReserve - $pendingReserved;
        $strategyOwnedMvTotal = array_sum($strategyOwnedMv);
        $investableCapital = $investableCash + $strategyOwnedMvTotal;

        $reservedByStrategy = $this->pendingReservedByStrategy($profile);
        [$lentByStrategy, $borrowedByStrategy] = $this->outstandingLoanCapitalByStrategy($profile);

        $enabled = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->with('activeVersion')
            ->orderBy('id')
            ->get();

        $allocationSum = 0.0;
        $unusedSum = 0.0;
        $strategies = [];
        $fundablePhysical = max(0.0, $availablePhysical - $requiredReserve);

        foreach ($enabled as $strategy) {
            $sid = (int) $strategy->id;
            $pct = $strategy->allocation_pct !== null ? (float) $strategy->allocation_pct : 0.0;
            $allocationSum += $pct;
            $allocated = $investableCapital * $pct / 100.0;
            $ownedMv = $strategyOwnedMv[$sid] ?? 0.0;
            $ownReserved = $reservedByStrategy[$sid] ?? 0.0;
            $lent = $lentByStrategy[$sid] ?? 0.0;
            $borrowed = $borrowedByStrategy[$sid] ?? 0.0;
            $committedToLending = 0.0;
            $deployed = $ownedMv + $ownReserved + $lent;
            $unused = max(0.0, $allocated - $deployed);
            $unusedSum += $unused;

            $recommendedMin = $this->recommendedMinimumHoldings($strategy);
            $retained = null;
            if ($recommendedMin !== null && $recommendedMin > 0) {
                $retained = NearestIntegerRupee::round($allocated / $recommendedMin);
            }

            $retainedForLending = $retained !== null ? (float) $retained : 0.0;
            // unused already excludes lent via deployed (§5.2). Do not subtract lent again
            // (that would double-count §8.2's already_lent). committed-to-lending: see below.
            $lendableSurplus = max(0.0, $unused - $retainedForLending - $committedToLending);

            $strategies[] = [
                'strategy_id' => $sid,
                'name' => $strategy->name,
                'allocation_pct' => round($pct, 4),
                'strategy_capital_allocation' => round($allocated, 4),
                'strategy_owned_market_value' => round($ownedMv, 4),
                'pending_execution_reserved' => round($ownReserved, 4),
                'lent_capital' => round($lent, 4),
                'borrowed_capital' => round($borrowed, 4),
                'already_committed_to_lending' => round($committedToLending, 4),
                'strategy_deployed_capital' => round($deployed, 4),
                'unused_allocation' => round($unused, 4),
                'recommended_minimum_holdings' => $recommendedMin,
                'minimum_retained_capital' => $retained,
                'minimum_retained_capital_is_physical_cash' => false,
                'available_for_lending' => FloorToRupee5000::floor($lendableSurplus),
                'strategy_available_capital' => round(min($unused, $fundablePhysical), 4),
            ];
        }

        $unallocatedCash = round(max(0.0, $availablePhysical - $requiredReserve - $unusedSum), 4);

        return [
            'physical_cash' => [
                'total_cash' => round($totalCash, 4),
                'pending_execution_reservations' => round($pendingReserved, 4),
                'available_physical_cash' => $availablePhysical,
                'cash_account_count' => 1,
                'strategy_physical_cash_accounts' => 0,
            ],
            'od19' => [
                'portfolio_cash_reserve_pct' => $reservePct,
                'total_invested_amount' => round($totalInvested, 4),
                'current_notional_portfolio_value' => round($totalNotional, 4),
                'reserve_base' => round($reserveBase, 4),
                'required_cash_reserve' => $requiredReserve,
                'reserve_shortfall' => $reserveShortfall,
                'reserve_shortfall_exists' => $reserveShortfallExists,
            ],
            'od20' => [
                'unallocated_cash' => $unallocatedCash,
                'is_presentation_only' => true,
                'is_ledger_bucket' => false,
            ],
            'od21' => [
                'withdrawals_blocked_by_reserve' => false,
                'dashboard_reserve_warning' => $reserveShortfallExists
                    ? 'Portfolio cash reserve is below the required level. Replenish portfolio/broker cash.'
                    : null,
            ],
            'investable_capital' => round($investableCapital, 4),
            'investable_cash_component' => round($investableCash, 4),
            'strategy_owned_market_value' => round($strategyOwnedMvTotal, 4),
            'unmanaged_market_value' => round($unmanagedMv, 4),
            'allocation_pct_sum' => round($allocationSum, 4),
            'allocation_pct_sum_is_100' => abs($allocationSum - 100.0) <= 0.01,
            'strategies' => $strategies,
        ];
    }

    /**
     * Outstanding loan principal grouped by lender and borrower.
     * Uses CapitalLoan.outstanding only (returned portions already excluded from that column).
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    protected function outstandingLoanCapitalByStrategy(PortfolioProfile $profile): array
    {
        $lent = [];
        $borrowed = [];
        $rows = CapitalLoan::query()
            ->where('profile_id', $profile->id)
            ->get(['lender_strategy_id', 'borrower_strategy_id', 'outstanding']);

        foreach ($rows as $loan) {
            $outstanding = (float) $loan->outstanding;
            if ($outstanding <= 0) {
                continue;
            }
            $lenderId = (int) $loan->lender_strategy_id;
            $borrowerId = (int) $loan->borrower_strategy_id;
            $lent[$lenderId] = ($lent[$lenderId] ?? 0.0) + $outstanding;
            $borrowed[$borrowerId] = ($borrowed[$borrowerId] ?? 0.0) + $outstanding;
        }

        return [$lent, $borrowed];
    }

    /**
     * @return array<int, float>
     */
    protected function pendingReservedByStrategy(PortfolioProfile $profile): array
    {
        $rows = TradingRecommendation::query()
            ->forProfile($profile)
            ->pendingExecution()
            ->withCashReservation()
            ->get(['id', 'strategy_version_id', 'reserved_amount']);

        $out = [];
        foreach ($rows as $rec) {
            $sid = $rec->owningStrategyId();
            if ($sid === null) {
                continue;
            }
            $out[$sid] = ($out[$sid] ?? 0.0) + (float) $rec->reserved_amount;
        }

        return $out;
    }

    protected function recommendedMinimumHoldings(TradingStrategy $strategy): ?int
    {
        $version = $strategy->activeVersion
            ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
        $config = is_array($version?->config_json) ? $version->config_json : [];
        $raw = $config['recommended_minimum_holdings']
            ?? ($config['portfolio_rules']['recommended_minimum_holdings'] ?? null);
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;
        if ($n <= 0) {
            return null;
        }

        return $n;
    }
}
