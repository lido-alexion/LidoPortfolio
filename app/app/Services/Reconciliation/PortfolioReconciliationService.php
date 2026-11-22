<?php

namespace App\Services\Reconciliation;

use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use App\Models\KiteCncTradeImport;
use App\Services\Broker\BrokerGateway;
use App\Services\CashManagementService;
use App\Services\Notification\NotificationPublisher;
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
        private NotificationPublisher $notifications,
        private KiteCncTradeSyncService $tradeSync,
    ) {}

    /** The legacy selector remains accepted; reconciliation always uses current holdings. */
    public function run(PortfolioProfile $profile, string $trigger, bool $ignoreTodayTransactions = false): PortfolioReconciliationRun
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
            $this->tradeSync->sync($profile, $trigger === 'session_baseline' ? 'session_baseline' : $trigger);
            $broker = $this->broker->portfolioSnapshot((int) $profile->user_id);
            if ($broker === null) {
                throw new \RuntimeException('Kite holdings or current cash could not be retrieved.');
            }
            // Reconciliation always uses the current ledger and settlement-aware
            // broker quantity. Keep accepting the legacy boolean request field,
            // but do not let it omit current-day CNC executions from safety state.
            $holdings = $profile->holdings()->with('stock')->get()->groupBy('stock_id')->map(function ($rows): array {
                $first = $rows->first();
                return [
                    'symbol' => (string) $first->stock->symbol,
                    'exchange' => (string) $first->stock->exchange,
                    'isin' => $this->normalisedIsin($first->stock->isin),
                    'quantity' => round((float) $rows->sum('quantity'), 4),
                    'cost' => round((float) $rows->sum('invested_amount'), 4),
                ];
            })->values()->all();
            $settlement = $this->settlementAwareBrokerHoldings($profile, $broker, $holdings);
            $broker['raw_holdings'] = $broker['holdings'] ?? [];
            $broker['comparison_holdings'] = $settlement['holdings'];
            $broker['settlement_adjustments'] = $settlement['adjustments'];

            return $this->recordSuccessful($profile, $trigger, $broker, [
                'captured_at' => now()->toISOString(), 'holdings' => $holdings,
                'comparison' => [
                    'comparison_method' => 'current StoX ledger versus Kite settled/T1 holdings with execution-proven settlement adjustments',
                    'ignore_today_transactions' => false,
                    'holdings_as_of' => null,
                    'settlement_adjustments' => $settlement['adjustments'],
                    'timezone' => 'Asia/Kolkata',
                    'cost_basis' => 'StoX fee-exclusive weighted-average open cost; transaction fees retained as evidence and not added to cost basis.',
                    'funds_comparison' => 'Current Kite cash versus current StoX cash; no historical Kite funds snapshot is available.',
                    'overall_status_note' => null,
                ],
                'cash_balance' => $this->cash->balance($profile),
            ], [
                'holding_cost' => max(0.0, (float) $this->settings->get('reconciliation_holding_cost_tolerance', '5')),
                'funds' => max(0.0, (float) $this->settings->get('reconciliation_funds_tolerance', '1')),
            ]);
        } catch (Throwable $error) {
            return $this->recordFailure($profile, $trigger, $error->getMessage());
        }
    }

    /**
     * Add only a CNC net quantity that exactly explains the broker/ledger gap.
     * Partial or ambiguous reflection remains a visible mismatch for review.
     * @return array{holdings:list<array<string,mixed>>,adjustments:list<array<string,mixed>>}
     */
    private function settlementAwareBrokerHoldings(PortfolioProfile $profile, array $broker, array $stox): array
    {
        $holdings = $broker['holdings'] ?? [];
        foreach ($holdings as &$row) {
            $row['quantity'] = round((float) ($row['quantity'] ?? 0) + (float) ($row['t1_quantity'] ?? 0) - (float) ($row['used_quantity'] ?? 0), 4);
        }
        unset($row);
        $latestDate = now('Asia/Kolkata')->toDateString();
        $imports = KiteCncTradeImport::query()->where('profile_id', $profile->id)
            ->whereIn('status', ['imported', 'matched_manual', 'matched_existing'])->whereNotNull('executed_at')
            ->whereDate('executed_at', '>=', now('Asia/Kolkata')->subDays(10)->toDateString())->get();
        $adjustments = [];
        $stockRows = collect($stox);
        $brokerRows = $this->groupHoldings($holdings);
        $pendingImports = $imports->filter(function ($trade) use ($latestDate): bool {
            $settlesBy = \App\Support\TradingCalendar::addSessions(\Illuminate\Support\Carbon::parse($trade->executed_at->toDateString(), 'Asia/Kolkata'), 1)->toDateString();
            return $latestDate <= $settlesBy;
        });
        foreach ($pendingImports->groupBy(fn ($trade) => ($trade->stock_id ?? 0).'|'.$trade->symbol.'|'.$trade->exchange) as $dayRows) {
            $first = $dayRows->first();
            $tradeDates = $dayRows->map(fn ($trade) => $trade->executed_at->toDateString())->unique();
            $settlesBy = $tradeDates->map(fn ($date) => \App\Support\TradingCalendar::addSessions(\Illuminate\Support\Carbon::parse($date, 'Asia/Kolkata'), 1)->toDateString())->max();
            $date = \Illuminate\Support\Carbon::parse($tradeDates->min(), 'Asia/Kolkata');
            $instrument = Stock::query()->find($first->stock_id);
            $local = $stockRows->first(fn (array $row) => ($instrument && strtoupper((string) ($row['symbol'] ?? '')) === strtoupper((string) $instrument->symbol))
                || (strtoupper((string) ($row['symbol'] ?? '')) === strtoupper((string) $first->symbol)
                && strtoupper((string) ($row['exchange'] ?? 'NSE')) === strtoupper((string) $first->exchange)));
            if ($local === null) continue;
            $identity = $this->holdingIdentity($local);
            $brokerRow = $brokerRows->get($identity, []);
            $brokerQty = (float) ($brokerRow['quantity'] ?? 0);
            $localQty = (float) ($local['quantity'] ?? 0);
            $net = (float) $dayRows->sum(fn ($trade) => (in_array($trade->side, ['buy', 'BUY'], true) ? 1 : -1) * (float) $trade->quantity);
            if (abs($localQty - $brokerQty) < 0.0001 || abs(($brokerQty + $net) - $localQty) >= 0.0001) continue;
            $brokerRows->put($identity, [...$brokerRow, 'symbol' => $first->symbol, 'exchange' => $first->exchange,
                'quantity' => $localQty, 'cost' => null, 'settlement_adjusted' => true]);
            $adjustments[] = ['symbol' => $first->symbol, 'exchange' => $first->exchange, 'trade_dates' => $tradeDates->values()->all(),
                'quantity_delta' => $net, 'settles_by' => $settlesBy, 'trade_ids' => $dayRows->pluck('broker_trade_id')->values()->all()];
        }
        return ['holdings' => $brokerRows->values()->all(), 'adjustments' => $adjustments];
    }

    /** @param array<string,mixed> $broker @param array<string,mixed> $stox @param array<string,float> $tolerances */
    public function recordSuccessful(PortfolioProfile $profile, string $trigger, array $broker, array $stox, array $tolerances): PortfolioReconciliationRun
    {
        return DB::transaction(function () use ($profile, $trigger, $broker, $stox, $tolerances): PortfolioReconciliationRun {
            $profile = PortfolioProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $supportedStocks = Stock::query()->where('exchange', 'NSE')->where('is_active', true)->get(['symbol', 'exchange', 'isin']);
            $supportedIsins = $supportedStocks->map(fn (Stock $stock): ?string => $this->normalisedIsin($stock->isin))->filter()->flip();
            $supportedSymbols = $supportedStocks->mapWithKeys(fn (Stock $stock): array => [$this->symbolIdentity([
                'symbol' => $stock->symbol,
                'exchange' => $stock->exchange,
            ]) => true]);
            $brokerHoldings = $this->groupHoldings($broker['comparison_holdings'] ?? $broker['holdings'] ?? []);
            $stoxHoldings = $this->groupHoldings($stox['holdings'] ?? []);
            $identities = $brokerHoldings->keys()->merge($stoxHoldings->keys())->filter()->unique()->sort()->values();
            $holdingDiscrepancies = [];
            $unsupported = [];
            foreach ($identities as $identity) {
                $brokerRow = $brokerHoldings->get($identity, []);
                $stoxRow = $stoxHoldings->get($identity, []);
                $reference = $brokerRow !== [] ? $brokerRow : $stoxRow;
                if (! $this->isSupportedHolding($reference, $supportedIsins, $supportedSymbols)) {
                    if ($brokerRow !== []) {
                        $unsupported[] = $brokerRow;
                    }

                    continue;
                }
                $brokerQty = (float) ($brokerRow['quantity'] ?? 0);
                $stoxQty = (float) ($stoxRow['quantity'] ?? 0);
                $brokerCost = isset($brokerRow['cost']) ? (float) $brokerRow['cost'] : null;
                $stoxCost = isset($stoxRow['cost']) ? (float) $stoxRow['cost'] : null;
                $costDifference = $brokerCost !== null && $stoxCost !== null ? round($brokerCost - $stoxCost, 4) : null;
                if ($brokerQty !== $stoxQty || ($costDifference !== null && abs($costDifference) > (float) ($tolerances['holding_cost'] ?? 0))) {
                    $symbol = (string) ($stoxRow['symbol'] ?? $brokerRow['symbol'] ?? '');
                    $stoxSymbol = $stoxRow['symbol'] ?? null;
                    $brokerSymbol = $brokerRow['symbol'] ?? null;
                    $isin = $this->normalisedIsin($stoxRow['isin'] ?? $brokerRow['isin'] ?? null);
                    $holdingDiscrepancies[] = compact('symbol', 'stoxSymbol', 'brokerSymbol', 'isin', 'brokerQty', 'stoxQty', 'brokerCost', 'stoxCost', 'costDifference');
                }
            }
            $brokerCash = (float) ($broker['current_cash'] ?? 0);
            $stoxCash = (float) ($stox['cash_balance'] ?? 0);
            $cashDifference = round($brokerCash - $stoxCash, 4);
            $holdingsStatus = $holdingDiscrepancies === [] ? 'reconciled' : 'mismatch';
            $cashComparable = ($broker['cash_comparable'] ?? true) === true;
            $fundsStatus = ! $cashComparable ? 'unknown'
                : (abs($cashDifference) <= (float) ($tolerances['funds'] ?? 0) ? 'reconciled' : 'mismatch');
            $historicalComparisonIncomplete = (bool) ($stox['comparison']['ignore_today_transactions'] ?? false);
            $overallStatus = $holdingsStatus === 'reconciled' && $fundsStatus === 'reconciled' && ! $historicalComparisonIncomplete
                ? 'reconciled'
                : 'attention_required';
            $run = PortfolioReconciliationRun::query()->create([
                'profile_id' => $profile->id, 'user_id' => $profile->user_id, 'trigger' => $trigger,
                'status' => 'completed', 'holdings_status' => $holdingsStatus, 'funds_status' => $fundsStatus,
                'overall_status' => $overallStatus, 'broker_snapshot' => $broker, 'stox_snapshot' => $stox,
                'tolerances' => $tolerances,
                'discrepancies' => ['holdings' => $holdingDiscrepancies, 'funds' => [
                    'broker_cash' => $brokerCash, 'stox_cash' => $stoxCash, 'difference' => $cashDifference,
                    'cash_comparable' => $cashComparable,
                    'broker_cash_basis' => $broker['cash_basis'] ?? 'current_cash',
                ]],
                'unsupported_instruments' => $unsupported, 'started_at' => now(), 'completed_at' => now(),
            ]);
            $executionBlocked = $holdingsStatus !== 'reconciled' || $fundsStatus !== 'reconciled'
                || ($historicalComparisonIncomplete && (bool) $profile->execution_blocked_by_reconciliation);
            $profile->forceFill([
                'reconciliation_holdings_status' => $holdingsStatus,
                'reconciliation_funds_status' => $fundsStatus,
                'reconciliation_overall_status' => $overallStatus,
                'execution_blocked_by_reconciliation' => $executionBlocked,
                'last_successful_reconciliation_at' => $run->completed_at,
                'last_reconciliation_failure' => null,
            ])->save();
            $conditionKey = 'portfolio:reconciliation:'.$profile->id;
            if ($overallStatus === 'reconciled') {
                $this->notifications->resolveCondition($conditionKey);
            } else {
                $parts = [];
                if ($holdingsStatus === 'mismatch') {
                    $parts[] = count($holdingDiscrepancies).' holding '.(count($holdingDiscrepancies) === 1 ? 'discrepancy' : 'discrepancies').' found; new live execution is blocked';
                }
                if ($fundsStatus === 'mismatch') {
                    $parts[] = 'cash differs by ₹'.number_format(abs($cashDifference), 2).'; new live execution is blocked';
                } elseif ($fundsStatus === 'unknown') {
                    $parts[] = 'Kite supplied available buying power rather than a comparable cash ledger; cash reconciliation is unverified and new live execution is blocked';
                }
                $this->notifications->publishCondition($conditionKey, [$profile->user], [
                    'notification_type' => 'portfolio.reconciliation_discrepancy',
                    'audience' => 'investor',
                    'severity' => 'action_required',
                    'title' => 'Portfolio reconciliation needs attention',
                    'message' => implode('. ', $parts).'. Correct the relevant StoX transactions, then run reconciliation again.',
                    'context' => [
                        'profile_id' => $profile->id,
                        'reconciliation_run_id' => $run->id,
                        'holdings_status' => $holdingsStatus,
                        'funds_status' => $fundsStatus,
                    ],
                    'primary_action' => ['label' => 'Review reconciliation', 'route' => '/dashboard'],
                ]);
            }

            return $run;
        });
    }

    /** @param array<string,mixed> $row */
    private function holdingIdentity(array $row): string
    {
        $isin = $this->normalisedIsin($row['isin'] ?? null);

        return $isin !== null ? 'isin:'.$isin : $this->symbolIdentity($row);
    }

    /** @param array<string,mixed> $row */
    private function symbolIdentity(array $row): string
    {
        return 'symbol:'.strtoupper(trim((string) ($row['exchange'] ?? 'NSE'))).':'.strtoupper(trim((string) ($row['symbol'] ?? '')));
    }

    private function normalisedIsin(mixed $value): ?string
    {
        $isin = strtoupper(trim((string) $value));

        return $isin !== '' ? $isin : null;
    }

    /** @param list<array<string,mixed>> $holdings */
    private function groupHoldings(array $holdings): \Illuminate\Support\Collection
    {
        return collect($holdings)->filter(fn (array $row): bool => (string) ($row['symbol'] ?? '') !== '')
            ->groupBy(fn (array $row): string => $this->holdingIdentity($row))
            ->map(function (\Illuminate\Support\Collection $rows): array {
                $first = $rows->first();
                $hasCompleteCost = $rows->every(fn (array $row): bool => isset($row['cost']));

                return array_merge($first, [
                    'quantity' => round((float) $rows->sum(fn (array $row): float => (float) ($row['quantity'] ?? 0)), 4),
                    'cost' => $hasCompleteCost ? round((float) $rows->sum(fn (array $row): float => (float) $row['cost']), 4) : null,
                ]);
            });
    }

    /** @param array<string,mixed> $row */
    private function isSupportedHolding(array $row, \Illuminate\Support\Collection $supportedIsins, \Illuminate\Support\Collection $supportedSymbols): bool
    {
        $isin = $this->normalisedIsin($row['isin'] ?? null);

        return $isin !== null ? $supportedIsins->has($isin) : $supportedSymbols->has($this->symbolIdentity($row));
    }

    private function recordFailure(PortfolioProfile $profile, string $trigger, string $failure): PortfolioReconciliationRun
    {
        return DB::transaction(function () use ($profile, $trigger, $failure): PortfolioReconciliationRun {
            $profile = PortfolioProfile::query()->lockForUpdate()->findOrFail($profile->id);
            if ($trigger === 'session_baseline') {
                $profile->forceFill(['execution_blocked_by_reconciliation' => true, 'last_reconciliation_failure' => $failure])->save();
            }
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
