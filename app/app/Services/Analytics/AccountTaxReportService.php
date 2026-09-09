<?php

namespace App\Services\Analytics;

use App\Models\AnalysisPreference;
use App\Models\Dividend;
use App\Models\OpeningTaxLot;
use App\Models\PortfolioProfile;
use App\Models\TaxLoss;
use App\Models\TaxRuleVersion;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountTaxReportService
{
    public function __construct(private FifoTaxLotCalculator $fifo) {}

    /**
     * @param array<int, int>|null $whatIfProfileIds
     * @return array<string, mixed>
     */
    public function calculate(User $user, string $financialYear, ?array $whatIfProfileIds = null, ?string $cutoff = null): array
    {
        $cutoff ??= now()->toDateTimeString();
        [$from, $to] = $this->financialYearBounds($financialYear);
        $profiles = PortfolioProfile::query()->where('user_id', $user->id)->get();
        $ownedIds = $profiles->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($whatIfProfileIds !== null) {
            $selectedIds = array_values(array_unique(array_map('intval', $whatIfProfileIds)));
            if (array_diff($selectedIds, $ownedIds) !== []) {
                throw ValidationException::withMessages(['portfolio_ids' => 'What-if portfolios must belong to the signed-in account.']);
            }
            $mode = 'what_if';
        } else {
            $excluded = AnalysisPreference::query()
                ->where('user_id', $user->id)
                ->whereNotNull('profile_id')
                ->where('include_in_account_tax', false)
                ->pluck('profile_id')->map(fn ($id) => (int) $id)->all();
            $selectedIds = array_values(array_diff($ownedIds, $excluded));
            $mode = 'configured';
        }

        $transferTransactionIds = DB::table('portfolio_internal_execution_transfers')
            ->join('portfolio_tos_recommendations as sell_recommendation', 'sell_recommendation.id', '=', 'portfolio_internal_execution_transfers.sell_recommendation_id')
            ->whereIn('sell_recommendation.profile_id', $selectedIds ?: [-1])
            ->get(['sell_transaction_id', 'buy_transaction_id'])
            ->flatMap(fn ($row) => [$row->sell_transaction_id, $row->buy_transaction_id])
            ->filter()->map(fn ($id) => (int) $id)->unique()->all();

        $transactions = Transaction::query()
            ->whereIn('profile_id', $selectedIds ?: [-1])
            ->where('transaction_date', '<=', $to)
            ->where('created_at', '<=', $cutoff)
            ->whereNotIn('id', $transferTransactionIds ?: [-1])
            ->orderBy('transaction_date')->orderBy('id')->get();
        $openingLots = OpeningTaxLot::query()
            ->whereIn('profile_id', $selectedIds ?: [-1])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('acquired_on')->orderBy('id')->get();

        $realized = [];
        $openLots = [];
        $limitations = [];
        $stockIds = $transactions->pluck('stock_id')->merge($openingLots->pluck('stock_id'))->unique();
        foreach ($stockIds as $stockId) {
            $result = $this->fifo->calculate(
                $transactions->where('stock_id', $stockId)->map(fn (Transaction $tx) => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'date' => $tx->transaction_date->toDateString(),
                    'quantity' => (float) $tx->quantity,
                    'price' => (float) $tx->price,
                    'fees' => (float) $tx->fees,
                    'corporate_action_supported' => $tx->corporate_action_id === null,
                ])->values()->all(),
                $openingLots->where('stock_id', $stockId)->map(fn (OpeningTaxLot $lot) => [
                    'id' => $lot->id,
                    'acquired_on' => $lot->acquired_on->toDateString(),
                    'quantity' => (float) $lot->quantity,
                    'cost_basis' => (float) $lot->cost_basis,
                ])->values()->all(),
            );
            foreach ($result['realized_disposals'] as $row) {
                if ($row['disposed_on'] >= $from && $row['disposed_on'] <= $to) {
                    $realized[] = ['stock_id' => (int) $stockId, ...$row];
                }
            }
            foreach ($result['open_lots'] as $row) {
                $openLots[] = ['stock_id' => (int) $stockId, ...$row];
            }
            $limitations = [...$limitations, ...$result['limitations']];
        }

        $dividends = Dividend::query()->where('user_id', $user->id)
            ->whereBetween('received_on', [$from, $to])->where('created_at', '<=', $cutoff)->orderBy('received_on')->get();
        $losses = TaxLoss::query()->where('user_id', $user->id)
            ->where('financial_year', $financialYear)->where('created_at', '<=', $cutoff)->orderBy('loss_type')->get();
        $shortTerm = collect($realized)->where('term', 'short_term')->sum('gain');
        $longTerm = collect($realized)->where('term', 'long_term')->sum('gain');
        $limitations = array_values(array_unique($limitations));
        [$estimatedTax, $appliedRules, $taxLimitations] = $this->estimateTax($realized, $losses);
        $limitations = array_values(array_unique([...$limitations, ...$taxLimitations]));

        return [
            'financial_year' => $financialYear,
            'period' => ['from' => $from, 'to' => $to],
            'calculation_mode' => $mode,
            'request_cutoff_at' => $cutoff,
            'portfolio_ids' => $selectedIds,
            'summary' => [
                'short_term_realized_gain' => round($shortTerm, 4),
                'long_term_realized_gain' => round($longTerm, 4),
                'dividend_income' => round((float) $dividends->sum('amount'), 4),
                'estimated_tax' => $estimatedTax,
            ],
            'realized_disposals' => $realized,
            'open_lots_informational' => $openLots,
            'dividends' => $dividends,
            'losses' => $losses,
            'tax_rule_versions' => $appliedRules,
            'completeness' => $limitations === [] ? 'complete' : 'incomplete',
            'limitations' => $limitations,
            'assumptions' => [
                'jurisdiction' => 'India',
                'lot_method' => 'fifo',
                'canonical_accounting_method' => 'wavg',
                'long_term_holding_days' => 365,
                'tax_advice' => false,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $realized
     * @return array{float|null, array<int, string>, array<int, string>}
     */
    private function estimateTax(array $realized, $losses): array
    {
        if ($realized === []) {
            return [0.0, [], []];
        }
        if ($losses->isNotEmpty() || collect($realized)->contains(fn (array $row) => $row['gain'] < 0)) {
            return [null, [], ['loss_setoff_requires_versioned_rule_engine']];
        }

        $buckets = [];
        $applied = [];
        foreach ($realized as $row) {
            $rule = TaxRuleVersion::query()
                ->whereDate('effective_from', '<=', $row['disposed_on'])
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $row['disposed_on']))
                ->orderByDesc('effective_from')->first();
            $longTerm = $rule !== null
                && $row['holding_days'] > (int) ($rule->rules['long_term_holding_days'] ?? 365);
            $term = $longTerm ? 'long_term' : 'short_term';
            $rateKey = $longTerm ? 'long_term_rate' : 'short_term_rate';
            if ($rule === null || ! is_numeric($rule->rules[$rateKey] ?? null)) {
                return [null, array_values(array_unique($applied)), ['tax_rate_rule_not_configured']];
            }
            $applied[] = $rule->version;
            $key = $rule->id.':'.$term;
            $buckets[$key] ??= ['term' => $term, 'gain' => 0.0, 'rules' => $rule->rules];
            $buckets[$key]['gain'] += max(0.0, (float) $row['gain']);
        }

        $tax = 0.0;
        foreach ($buckets as $bucket) {
            $longTerm = $bucket['term'] === 'long_term';
            $exemption = $longTerm ? (float) ($bucket['rules']['long_term_exemption'] ?? 0.0) : 0.0;
            $rate = (float) $bucket['rules'][$longTerm ? 'long_term_rate' : 'short_term_rate'];
            $tax += max(0.0, $bucket['gain'] - $exemption) * $rate;
        }

        return [round($tax, 4), array_values(array_unique($applied)), []];
    }

    /** @return array{string, string} */
    private function financialYearBounds(string $financialYear): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $financialYear, $match)
            || ((int) $match[2]) !== (((int) $match[1] + 1) % 100)) {
            throw ValidationException::withMessages(['financial_year' => 'Use a consecutive Indian financial year such as 2025-26.']);
        }

        return [$match[1].'-04-01', ((int) $match[1] + 1).'-03-31'];
    }
}
