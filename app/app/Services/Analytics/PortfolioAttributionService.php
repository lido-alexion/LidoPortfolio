<?php

namespace App\Services\Analytics;

use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\HoldingAdoption;
use App\Models\PortfolioProfile;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Services\HistoricalHoldingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class PortfolioAttributionService
{
    public function __construct(private HistoricalHoldingsService $history) {}

    /** @return array<string, mixed> */
    public function calculate(PortfolioProfile $profile, string $from, string $to): array
    {
        $cutoff = now()->toDateTimeString();
        $opening = $this->history->asOf($profile, $from, $cutoff);
        $closing = $this->history->asOf($profile, $to, $cutoff);
        $externalFlow = (float) CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->whereIn('entry_type', [CashLedgerEntry::TYPE_DEPOSIT, CashLedgerEntry::TYPE_WITHDRAWAL])
            ->where('entry_date', '>', $from)->where('entry_date', '<=', $to)
            ->where('created_at', '<=', $cutoff)->sum('amount');
        $openingValue = $opening['totals']['total_value'];
        $closingValue = $closing['totals']['total_value'];
        $economicResult = $openingValue === null || $closingValue === null
            ? null
            : round((float) $closingValue - (float) $openingValue - $externalFlow, 4);

        $sells = Transaction::query()->where('profile_id', $profile->id)->where('type', 'sell')
            ->where('transaction_date', '>', $from)->where('transaction_date', '<=', $to)
            ->where('created_at', '<=', $cutoff)->orderBy('transaction_date')->orderBy('id')->get();
        $transactions = Transaction::query()->where('profile_id', $profile->id)
            ->where('transaction_date', '<=', $to)->where('created_at', '<=', $cutoff)
            ->orderBy('transaction_date')->orderBy('id')->get();
        $dimensions = [];
        $missingRealization = false;
        foreach ($sells as $sell) {
            $ownerKey = Holding::isValidOwnerKey($sell->owner_key) ? $sell->owner_key : Holding::OWNER_UNMANAGED;
            $dimensions[$ownerKey] ??= [
                'owner_key' => $ownerKey,
                'strategy_id' => Holding::strategyIdFromOwnerKey($ownerKey),
                'realized_gross' => 0.0,
                'allocated_charges' => 0.0,
                'net_realized_contribution' => 0.0,
            ];
            if ($sell->realized_pl === null || $sell->squared_off_fees === null) {
                $missingRealization = true;
                continue;
            }
            $dimensions[$ownerKey]['realized_gross'] += (float) $sell->realized_pl;
            $dimensions[$ownerKey]['allocated_charges'] += (float) $sell->squared_off_fees;
            $dimensions[$ownerKey]['net_realized_contribution'] += (float) $sell->realized_pl - (float) $sell->squared_off_fees;
        }

        $dimensions[Holding::OWNER_UNMANAGED] ??= [
            'owner_key' => Holding::OWNER_UNMANAGED,
            'strategy_id' => null,
            'realized_gross' => 0.0,
            'allocated_charges' => 0.0,
            'net_realized_contribution' => 0.0,
        ];
        $hasAdoption = HoldingAdoption::query()->where('profile_id', $profile->id)
            ->where('created_at', '<=', $cutoff)->exists();
        [$unrealizedOpening, $openingOwnerComplete, $openingOwnerLimitations] = $hasAdoption
            ? [[], false, ['ownership_adoption_history_not_reconstructable']]
            : $this->unrealizedByOwner($transactions, $from, $cutoff);
        [$unrealizedClosing, $closingOwnerComplete, $closingOwnerLimitations] = $hasAdoption
            ? [[], false, []]
            : $this->unrealizedByOwner($transactions, $to, $cutoff);
        foreach (array_unique([...array_keys($unrealizedOpening), ...array_keys($unrealizedClosing)]) as $ownerKey) {
            $dimensions[$ownerKey] ??= [
                'owner_key' => $ownerKey,
                'strategy_id' => Holding::strategyIdFromOwnerKey($ownerKey),
                'realized_gross' => 0.0,
                'allocated_charges' => 0.0,
                'net_realized_contribution' => 0.0,
            ];
            $dimensions[$ownerKey]['unrealized_change'] = round(
                ($unrealizedClosing[$ownerKey] ?? 0.0) - ($unrealizedOpening[$ownerKey] ?? 0.0), 4,
            );
        }
        $dimensions = array_values(array_map(function (array $row): array {
            $row['unrealized_change'] ??= null;
            $row['explained_contribution'] = $row['unrealized_change'] === null
                ? $row['net_realized_contribution']
                : $row['net_realized_contribution'] + $row['unrealized_change'];
            foreach (['realized_gross', 'allocated_charges', 'net_realized_contribution', 'unrealized_change', 'explained_contribution'] as $field) {
                if ($row[$field] === null) {
                    continue;
                }
                $row[$field] = round($row[$field], 4);
            }
            return $row;
        }, $dimensions));
        usort($dimensions, fn (array $a, array $b): int => strcmp($a['owner_key'], $b['owner_key']));
        $explained = round(array_sum(array_column($dimensions, 'explained_contribution')), 4);
        $residual = $economicResult === null ? null : round($economicResult - $explained, 4);
        $endpointComplete = $opening['completeness']['total_value_complete']
            && $closing['completeness']['total_value_complete'];

        return [
            'profile_id' => $profile->id,
            'period' => ['from' => $from, 'to' => $to],
            'request_cutoff_at' => $cutoff,
            'economic_result' => $economicResult,
            'external_flow' => round($externalFlow, 4),
            'dimensions' => $dimensions,
            'explained_net_realized' => round(array_sum(array_column($dimensions, 'net_realized_contribution')), 4),
            'explained_total' => $explained,
            'reconciliation_residual' => $residual,
            'reconciles' => $economicResult !== null && abs(($explained + $residual) - $economicResult) < 0.0001,
            'completeness' => $endpointComplete && ! $missingRealization && $openingOwnerComplete && $closingOwnerComplete
                ? ($residual !== null && abs($residual) < 0.01 ? 'complete' : 'estimate_with_limitations')
                : 'incomplete',
            'limitations' => array_values(array_filter([
                ...$openingOwnerLimitations,
                ...$closingOwnerLimitations,
                $residual !== null && abs($residual) >= 0.01 ? 'cash_income_and_other_effects_remain_in_reconciliation_residual' : null,
                $missingRealization ? 'missing_sell_realization_evidence' : null,
                ! $endpointComplete ? 'incomplete_endpoint_valuation' : null,
            ])),
            'method' => 'reconciliation_based_not_causal',
        ];
    }

    /**
     * Reconstructs fee-exclusive WAVG state by persisted owner. Ownership adoption
     * is handled by the caller because older adoption rows do not retain enough
     * quantity/cost evidence to reconstruct a truthful historical transfer.
     *
     * @return array{array<string, float>, bool, array<int, string>}
     */
    private function unrealizedByOwner(Collection $transactions, string $date, string $cutoff): array
    {
        $state = [];
        $limitations = [];
        foreach ($transactions as $transaction) {
            if ($transaction->transaction_date->toDateString() > $date) {
                continue;
            }
            $owner = Holding::isValidOwnerKey($transaction->owner_key)
                ? $transaction->owner_key : Holding::OWNER_UNMANAGED;
            $stockId = (int) $transaction->stock_id;
            $state[$owner][$stockId] ??= ['quantity' => 0.0, 'invested' => 0.0];
            $bucket = &$state[$owner][$stockId];
            $quantity = (float) $transaction->quantity;
            if ($transaction->type === 'buy') {
                $bucket['quantity'] += $quantity;
                $bucket['invested'] += $quantity * (float) $transaction->price;
            } elseif ($transaction->type === 'sell') {
                if ($quantity > $bucket['quantity'] + 0.00001) {
                    $limitations[] = 'owner_level_historical_oversell:'.$transaction->id;
                    unset($bucket);
                    continue;
                }
                $average = $bucket['quantity'] > 0.0 ? $bucket['invested'] / $bucket['quantity'] : 0.0;
                $bucket['quantity'] -= $quantity;
                $bucket['invested'] = $average * $bucket['quantity'];
            } else {
                $limitations[] = 'unsupported_owner_attribution_transaction:'.$transaction->id;
            }
            unset($bucket);
        }

        $unrealized = [];
        foreach ($state as $owner => $stocks) {
            foreach ($stocks as $stockId => $bucket) {
                if ($bucket['quantity'] <= 0.00001) {
                    continue;
                }
                $price = StockPrice::query()->where('stock_id', $stockId)
                    ->where('price_date', '<=', CarbonImmutable::parse($date)->endOfDay())
                    ->where('created_at', '<=', $cutoff)->orderByDesc('price_date')->first();
                $close = $price?->adjusted_close_price ?? $price?->close_price;
                if ($close === null || (float) $close <= 0.0) {
                    $limitations[] = 'missing_owner_attribution_price:'.$stockId.':'.$date;
                    continue;
                }
                $unrealized[$owner] = ($unrealized[$owner] ?? 0.0)
                    + ($bucket['quantity'] * (float) $close) - $bucket['invested'];
            }
        }

        return [$unrealized, $limitations === [], array_values(array_unique($limitations))];
    }
}
