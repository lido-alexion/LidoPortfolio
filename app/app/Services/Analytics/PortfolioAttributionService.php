<?php

namespace App\Services\Analytics;

use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Transaction;
use App\Services\HistoricalHoldingsService;

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
        $dimensions = array_values(array_map(function (array $row): array {
            foreach (['realized_gross', 'allocated_charges', 'net_realized_contribution'] as $field) {
                $row[$field] = round($row[$field], 4);
            }
            return $row;
        }, $dimensions));
        usort($dimensions, fn (array $a, array $b): int => strcmp($a['owner_key'], $b['owner_key']));
        $explained = round(array_sum(array_column($dimensions, 'net_realized_contribution')), 4);
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
            'explained_net_realized' => $explained,
            'reconciliation_residual' => $residual,
            'reconciles' => $economicResult !== null && abs(($explained + $residual) - $economicResult) < 0.0001,
            'completeness' => $endpointComplete && ! $missingRealization ? 'estimate_with_limitations' : 'incomplete',
            'limitations' => array_values(array_filter([
                'unrealized_and_cash_effects_are_reconciliation_residual',
                $missingRealization ? 'missing_sell_realization_evidence' : null,
                ! $endpointComplete ? 'incomplete_endpoint_valuation' : null,
            ])),
            'method' => 'reconciliation_based_not_causal',
        ];
    }
}
