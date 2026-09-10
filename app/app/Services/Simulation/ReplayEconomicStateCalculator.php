<?php

namespace App\Services\Simulation;

final class ReplayEconomicStateCalculator
{
    /**
     * @param array<string, mixed> $state
     * @param array<string, string|null> $settings
     * @param array<int, float> $prices
     * @return array<string, mixed>
     */
    public function advance(array $state, array $settings, array $prices): array
    {
        $cash = (float) ($state['cash_balance'] ?? 0);
        $reservedByStrategy = [];
        foreach ($state['reservations'] ?? [] as $reservation) {
            $sid = (int) ($reservation['strategy_id'] ?? 0);
            $reservedByStrategy[$sid] = ($reservedByStrategy[$sid] ?? 0) + (float) ($reservation['amount'] ?? 0);
        }
        $reserved = array_sum($reservedByStrategy);

        $invested = 0.0;
        $notional = 0.0;
        $ownedByStrategy = [];
        $unmanaged = 0.0;
        foreach ($state['holdings'] ?? [] as $holding) {
            $quantity = (float) ($holding['quantity'] ?? 0);
            if ($quantity <= 0) continue;
            $stockId = (int) ($holding['stock_id'] ?? 0);
            $cost = (float) ($holding['invested_amount'] ?? ($quantity * (float) ($holding['avg_buy_price'] ?? 0)));
            $marketValue = $quantity * (float) ($prices[$stockId] ?? $holding['as_of_price'] ?? $holding['avg_buy_price'] ?? 0);
            $invested += $cost;
            $notional += $marketValue;
            $sid = (int) ($holding['strategy_id'] ?? 0);
            if ($sid > 0) $ownedByStrategy[$sid] = ($ownedByStrategy[$sid] ?? 0) + $marketValue;
            else $unmanaged += $marketValue;
        }

        [$lent, $borrowed] = $this->loanBalances($state);
        $reservePct = max(0.0, (float) ($settings['portfolio_cash_reserve_pct'] ?? 0));
        $requiredReserve = max($invested, $notional) * ($reservePct / 100);
        $availablePhysical = max(0.0, $cash - $reserved);
        $fundablePhysical = max(0.0, $availablePhysical - $requiredReserve);
        $investableCash = $cash - $requiredReserve - $reserved;
        $investableCapital = $investableCash + array_sum($ownedByStrategy);

        $strategyRows = [];
        foreach ($state['strategies'] ?? [] as $strategy) {
            $sid = (int) ($strategy['strategy_id'] ?? 0);
            $pct = (float) ($strategy['allocation_pct'] ?? 0);
            $allocated = $investableCapital * ($pct / 100);
            $owned = $ownedByStrategy[$sid] ?? 0.0;
            $ownReserved = $reservedByStrategy[$sid] ?? 0.0;
            $ownLent = $lent[$sid] ?? 0.0;
            $unused = max(0.0, $allocated - $owned - $ownReserved - $ownLent);
            $strategyRows[] = array_merge($strategy, [
                'strategy_capital_allocation' => $this->money($allocated),
                'owned_market_value' => $this->money($owned),
                'reserved' => $this->money($ownReserved),
                'lent' => $this->money($ownLent),
                'borrowed' => $this->money($borrowed[$sid] ?? 0.0),
                'unused_allocation' => $this->money($unused),
                'available_capital' => $this->money(min($unused, $fundablePhysical)),
            ]);
        }

        $state['strategies'] = $strategyRows;
        $state['valuation'] = [
            'cash' => $this->money($cash), 'invested_amount' => $this->money($invested),
            'market_value' => $this->money($notional), 'unmanaged_market_value' => $this->money($unmanaged),
            'total_value' => $this->money($cash + $notional),
        ];
        $state['capital'] = [
            'required_cash_reserve' => $this->money($requiredReserve),
            'pending_execution_reservations' => $this->money($reserved),
            'available_physical_cash' => $this->money($availablePhysical),
            'investable_capital' => $this->money($investableCapital),
        ];

        return $state;
    }

    /** @return array{array<int,float>,array<int,float>} */
    private function loanBalances(array $state): array
    {
        $lent = []; $borrowed = [];
        foreach (array_merge($state['loans'] ?? [], $state['recall_bridge_loans'] ?? []) as $loan) {
            $amount = max(0.0, (float) ($loan['outstanding'] ?? 0));
            $lender = (int) ($loan['lender_strategy_id'] ?? 0);
            $borrower = (int) ($loan['borrower_strategy_id'] ?? 0);
            if ($lender > 0) $lent[$lender] = ($lent[$lender] ?? 0) + $amount;
            if ($borrower > 0) $borrowed[$borrower] = ($borrowed[$borrower] ?? 0) + $amount;
        }
        return [$lent, $borrowed];
    }

    private function money(float $value): float { return round($value, 4); }
}
