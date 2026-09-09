<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioCsvExportService
{
    public const SCHEMA_VERSION = 'stox.portfolio-export.v1';

    public function __construct(
        protected CashManagementService $cash,
        protected HistoricalHoldingsService $historical,
        protected PortfolioDateComparisonService $comparison,
    ) {}

    /** @param array<string, mixed> $options */
    public function response(PortfolioProfile $profile, string $dataset, array $options): StreamedResponse
    {
        $cutoff = now()->toIso8601String();
        [$context, $headers, $rows] = match ($dataset) {
            'cash_statement' => $this->cashStatement($profile, $options),
            'historical_holdings' => $this->historicalHoldings($profile, $options),
            'portfolio_compare' => $this->portfolioCompare($profile, $options),
        };
        $filename = 'stox-'.$dataset.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($profile, $dataset, $cutoff, $context, $headers, $rows) {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['schema_version', self::SCHEMA_VERSION]);
            fputcsv($stream, ['dataset', $dataset]);
            fputcsv($stream, ['portfolio_id', $profile->id]);
            fputcsv($stream, ['portfolio_name', $profile->name]);
            fputcsv($stream, ['currency', 'INR']);
            fputcsv($stream, ['request_cutoff', $cutoff]);
            foreach ($context as $key => $value) {
                fputcsv($stream, [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value]);
            }
            fputcsv($stream, []);
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($key) => $row[$key] ?? null, $headers));
            }
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{array<string, mixed>, list<string>, list<array<string, mixed>>} */
    protected function cashStatement(PortfolioProfile $profile, array $options): array
    {
        $page = 1;
        $rows = [];
        do {
            $statement = $this->cash->statement($profile, $options['from'] ?? null, $options['to'] ?? null, $page, 100);
            $rows = array_merge($rows, $statement['entries']);
            $page++;
        } while ($page <= $statement['pagination']['last_page']);

        return [[
            'from' => $statement['from'],
            'to' => $statement['to'],
            'opening_balance' => $statement['opening_balance'],
            'closing_balance' => $statement['closing_balance'],
            'complete' => true,
        ], [
            'id', 'entry_date', 'created_at', 'entry_type', 'category', 'amount', 'running_balance',
            'reason', 'transaction_id', 'recommendation_id', 'user_id',
        ], $rows];
    }

    /** @return array{array<string, mixed>, list<string>, list<array<string, mixed>>} */
    protected function historicalHoldings(PortfolioProfile $profile, array $options): array
    {
        $result = $this->historical->asOf($profile, $options['as_of']);

        return [[
            'as_of' => $result['as_of'],
            'cash_balance' => $result['totals']['cash_balance'],
            'total_value' => $result['totals']['total_value'],
            'complete' => $result['completeness']['total_value_complete'],
        ], [
            'stock_id', 'symbol', 'name', 'exchange', 'quantity', 'avg_buy_price', 'invested_amount',
            'as_of_price', 'price_as_of', 'price_source', 'market_value', 'unrealized_profit',
        ], $result['holdings']];
    }

    /** @return array{array<string, mixed>, list<string>, list<array<string, mixed>>} */
    protected function portfolioCompare(PortfolioProfile $profile, array $options): array
    {
        $result = $this->comparison->compare($profile, $options['date_a'], $options['date_b']);

        return [[
            'date_a' => $result['date_a'],
            'date_b' => $result['date_b'],
            'value_change_is_investment_return' => false,
            'external_flow_net' => $result['external_flows']['net'],
            'adjustments' => $result['external_flows']['adjustments'],
        ], [
            'stock_id', 'symbol', 'name', 'classification', 'quantity_a', 'quantity_b', 'quantity_delta',
            'market_value_a', 'market_value_b', 'price_as_of_a', 'price_as_of_b',
        ], $result['holdings']];
    }
}
