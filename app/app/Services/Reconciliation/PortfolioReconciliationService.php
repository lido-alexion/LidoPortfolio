<?php

namespace App\Services\Reconciliation;

use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

final class PortfolioReconciliationService
{
    /** @param array<string,mixed> $broker @param array<string,mixed> $stox @param array<string,float> $tolerances */
    public function recordSuccessful(PortfolioProfile $profile, string $trigger, array $broker, array $stox, array $tolerances): PortfolioReconciliationRun
    {
        return DB::transaction(function () use ($profile, $trigger, $broker, $stox, $tolerances): PortfolioReconciliationRun {
            $profile = PortfolioProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $supported = Stock::query()->where('exchange', 'NSE')->where('is_active', true)->pluck('id', 'symbol');
            $brokerHoldings = collect($broker['holdings'] ?? [])->keyBy(fn (array $row): string => (string) ($row['symbol'] ?? ''));
            $stoxHoldings = collect($stox['holdings'] ?? [])->keyBy(fn (array $row): string => (string) ($row['symbol'] ?? ''));
            $symbols = $brokerHoldings->keys()->merge($stoxHoldings->keys())->filter()->unique()->sort()->values();
            $holdingDiscrepancies = [];
            $unsupported = [];
            foreach ($symbols as $symbol) {
                $brokerRow = $brokerHoldings->get($symbol, []);
                if (! $supported->has($symbol)) {
                    if ($brokerRow !== []) {
                        $unsupported[] = $brokerRow;
                    }

                    continue;
                }
                $brokerQty = (float) ($brokerRow['quantity'] ?? 0);
                $stoxRow = $stoxHoldings->get($symbol, []);
                $stoxQty = (float) ($stoxRow['quantity'] ?? 0);
                $brokerCost = isset($brokerRow['cost']) ? (float) $brokerRow['cost'] : null;
                $stoxCost = isset($stoxRow['cost']) ? (float) $stoxRow['cost'] : null;
                $costDifference = $brokerCost !== null && $stoxCost !== null ? round($brokerCost - $stoxCost, 4) : null;
                if ($brokerQty !== $stoxQty || ($costDifference !== null && abs($costDifference) > (float) ($tolerances['holding_cost'] ?? 0))) {
                    $holdingDiscrepancies[] = compact('symbol', 'brokerQty', 'stoxQty', 'brokerCost', 'stoxCost', 'costDifference');
                }
            }
            $brokerCash = (float) ($broker['current_cash'] ?? 0);
            $stoxCash = (float) ($stox['cash_balance'] ?? 0);
            $cashDifference = round($brokerCash - $stoxCash, 4);
            $holdingsStatus = $holdingDiscrepancies === [] ? 'reconciled' : 'mismatch';
            $fundsStatus = abs($cashDifference) <= (float) ($tolerances['funds'] ?? 0) ? 'reconciled' : 'mismatch';
            $overallStatus = $holdingsStatus === 'reconciled' && $fundsStatus === 'reconciled' ? 'reconciled' : 'attention_required';
            $run = PortfolioReconciliationRun::query()->create([
                'profile_id' => $profile->id, 'user_id' => $profile->user_id, 'trigger' => $trigger,
                'status' => 'completed', 'holdings_status' => $holdingsStatus, 'funds_status' => $fundsStatus,
                'overall_status' => $overallStatus, 'broker_snapshot' => $broker, 'stox_snapshot' => $stox,
                'tolerances' => $tolerances,
                'discrepancies' => ['holdings' => $holdingDiscrepancies, 'funds' => [
                    'broker_cash' => $brokerCash, 'stox_cash' => $stoxCash, 'difference' => $cashDifference,
                ]],
                'unsupported_instruments' => $unsupported, 'started_at' => now(), 'completed_at' => now(),
            ]);
            $profile->forceFill([
                'reconciliation_holdings_status' => $holdingsStatus,
                'reconciliation_funds_status' => $fundsStatus,
                'reconciliation_overall_status' => $overallStatus,
                'execution_blocked_by_reconciliation' => $holdingsStatus === 'mismatch',
                'last_successful_reconciliation_at' => $run->completed_at,
                'last_reconciliation_failure' => null,
            ])->save();

            return $run;
        });
    }
}
