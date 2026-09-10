<?php

namespace App\Services\Reconciliation;

use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use App\Services\Broker\BrokerGateway;
use App\Services\CashManagementService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PortfolioReconciliationService
{
    public function __construct(
        private BrokerGateway $broker,
        private CashManagementService $cash,
        private SettingsService $settings,
    ) {}

    public function run(PortfolioProfile $profile, string $trigger): PortfolioReconciliationRun
    {
        $result = Cache::lock('portfolio-reconciliation:'.$profile->id, 60)->get(
            fn () => $this->runLocked($profile, $trigger)
        );
        if (! $result instanceof PortfolioReconciliationRun) {
            throw new \RuntimeException('Reconciliation is already in progress for this portfolio.');
        }

        return $result;
    }

    private function runLocked(PortfolioProfile $profile, string $trigger): PortfolioReconciliationRun
    {
        if ($profile->isPaper() || $profile->isManualExecution()) {
            throw new \InvalidArgumentException('Portfolio reconciliation is available only for live Semi-Automatic or Automatic portfolios.');
        }
        try {
            $broker = $this->broker->portfolioSnapshot((int) $profile->user_id);
            if ($broker === null) {
                throw new \RuntimeException('Kite holdings or current cash could not be retrieved.');
            }
            $holdings = $profile->holdings()->with('stock')->get()->groupBy('stock_id')->map(function ($rows): array {
                $first = $rows->first();

                return [
                    'symbol' => (string) $first->stock->symbol,
                    'quantity' => round((float) $rows->sum('quantity'), 4),
                    'cost' => round((float) $rows->sum('invested_amount'), 4),
                ];
            })->values()->all();

            return $this->recordSuccessful($profile, $trigger, $broker, [
                'captured_at' => now()->toISOString(), 'holdings' => $holdings,
                'cash_balance' => $this->cash->balance($profile),
            ], [
                'holding_cost' => max(0.0, (float) $this->settings->get('reconciliation_holding_cost_tolerance', '1')),
                'funds' => max(0.0, (float) $this->settings->get('reconciliation_funds_tolerance', '1')),
            ]);
        } catch (Throwable $error) {
            return $this->recordFailure($profile, $trigger, $error->getMessage());
        }
    }

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

    private function recordFailure(PortfolioProfile $profile, string $trigger, string $failure): PortfolioReconciliationRun
    {
        return DB::transaction(function () use ($profile, $trigger, $failure): PortfolioReconciliationRun {
            $profile = PortfolioProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $run = PortfolioReconciliationRun::query()->create([
                'profile_id' => $profile->id, 'user_id' => $profile->user_id, 'trigger' => $trigger,
                'status' => 'sync_failed', 'holdings_status' => 'unknown', 'funds_status' => 'unknown',
                'overall_status' => 'unknown', 'failure' => $failure, 'started_at' => now(), 'completed_at' => now(),
            ]);
            $profile->forceFill(['last_reconciliation_failure' => $failure])->save();

            return $run;
        });
    }
}
