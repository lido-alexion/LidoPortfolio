<?php

namespace App\Services;

use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\Transaction;

class PortfolioDateComparisonService
{
    public function __construct(
        protected HistoricalHoldingsService $historical,
    ) {}

    /** @return array<string, mixed> */
    public function compare(PortfolioProfile $profile, string $dateA, string $dateB): array
    {
        $a = $this->historical->asOf($profile, $dateA);
        $b = $this->historical->asOf($profile, $dateB);
        $aByStock = collect($a['holdings'])->keyBy('stock_id');
        $bByStock = collect($b['holdings'])->keyBy('stock_id');

        $holdings = $aByStock->keys()->merge($bByStock->keys())->unique()->map(function ($stockId) use ($aByStock, $bByStock) {
            $atA = $aByStock->get($stockId);
            $atB = $bByStock->get($stockId);
            $quantityA = (float) ($atA['quantity'] ?? 0);
            $quantityB = (float) ($atB['quantity'] ?? 0);
            $classification = match (true) {
                $quantityA == 0.0 && $quantityB > 0 => 'entered',
                $quantityA > 0 && $quantityB == 0.0 => 'exited',
                $quantityB > $quantityA => 'increased',
                $quantityB < $quantityA => 'reduced',
                default => 'unchanged',
            };

            return [
                'stock_id' => (int) $stockId,
                'symbol' => $atB['symbol'] ?? $atA['symbol'] ?? null,
                'name' => $atB['name'] ?? $atA['name'] ?? null,
                'classification' => $classification,
                'quantity_a' => $quantityA,
                'quantity_b' => $quantityB,
                'quantity_delta' => round($quantityB - $quantityA, 4),
                'market_value_a' => $atA['market_value'] ?? 0.0,
                'market_value_b' => $atB['market_value'] ?? 0.0,
                'price_as_of_a' => $atA['price_as_of'] ?? null,
                'price_as_of_b' => $atB['price_as_of'] ?? null,
            ];
        })->sortBy(fn (array $row) => (string) $row['symbol'])->values()->all();

        $intervalCash = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->whereDate('entry_date', '>', $dateA)
            ->whereDate('entry_date', '<=', $dateB);
        $deposits = (float) (clone $intervalCash)->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)->sum('amount');
        $withdrawals = abs((float) (clone $intervalCash)->where('entry_type', CashLedgerEntry::TYPE_WITHDRAWAL)->sum('amount'));
        $adjustments = (float) (clone $intervalCash)->where('entry_type', CashLedgerEntry::TYPE_ADJUSTMENT)->sum('amount');

        $transactions = Transaction::query()
            ->with('stock:id,symbol,name')
            ->where('profile_id', $profile->id)
            ->whereDate('transaction_date', '>', $dateA)
            ->whereDate('transaction_date', '<=', $dateB)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'date' => optional($transaction->transaction_date)?->toDateString(),
                'symbol' => $transaction->stock?->symbol,
                'type' => $transaction->type,
                'quantity' => (float) $transaction->quantity,
                'price' => (float) $transaction->price,
            ])->all();

        return [
            'date_a' => $dateA,
            'date_b' => $dateB,
            'endpoint_a' => $this->endpoint($a),
            'endpoint_b' => $this->endpoint($b),
            'deltas' => [
                'cash' => $this->delta($a['totals']['cash_balance'], $b['totals']['cash_balance']),
                'holdings_market_value' => $this->delta($a['totals']['market_value'], $b['totals']['market_value']),
                'total_value' => $this->delta($a['totals']['total_value'], $b['totals']['total_value']),
                'label' => 'Change in portfolio value (not investment return)',
            ],
            'external_flows' => [
                'deposits' => round($deposits, 4),
                'withdrawals' => round($withdrawals, 4),
                'net' => round($deposits - $withdrawals, 4),
                'adjustments' => round($adjustments, 4),
            ],
            'holdings' => $holdings,
            'transactions' => $transactions,
            'current_truth' => true,
        ];
    }

    /** @param array<string, mixed> $historical */
    protected function endpoint(array $historical): array
    {
        return [
            'date' => $historical['as_of'],
            'holdings_market_value' => $historical['totals']['market_value'],
            'cash' => $historical['totals']['cash_balance'],
            'total_value' => $historical['totals']['total_value'],
            'completeness' => $historical['completeness'],
        ];
    }

    protected function delta(mixed $a, mixed $b): ?float
    {
        return $a === null || $b === null ? null : round((float) $b - (float) $a, 4);
    }
}
