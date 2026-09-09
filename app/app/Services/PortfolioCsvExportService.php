<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
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
        $options['_cutoff'] = $cutoff;
        [$context, $headers, $rows] = match ($dataset) {
            'cash_statement' => $this->cashStatement($profile, $options),
            'historical_holdings' => $this->historicalHoldings($profile, $options),
            'portfolio_compare' => $this->portfolioCompare($profile, $options),
            'current_holdings' => $this->currentHoldings($profile),
            'transactions' => $this->transactions($profile, $options),
            'portfolio_value_history' => $this->portfolioValueHistory($profile, $options),
            'recommendations' => $this->recommendations($profile, $options),
            'orders_trades' => $this->ordersTrades($profile, $options),
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

    protected function currentHoldings(PortfolioProfile $profile): array
    {
        $rows = Holding::query()->with('stock:id,symbol,name,exchange')
            ->where('profile_id', $profile->id)->where('quantity', '>', 0)
            ->orderBy('stock_id')->get()->map(fn (Holding $holding) => [
                'holding_id' => $holding->id, 'stock_id' => $holding->stock_id,
                'symbol' => $holding->stock?->symbol, 'name' => $holding->stock?->name,
                'exchange' => $holding->stock?->exchange, 'quantity' => (float) $holding->quantity,
                'avg_buy_price' => (float) $holding->avg_buy_price,
                'invested_amount' => (float) $holding->invested_amount,
                'owner_key' => $holding->owner_key,
                'updated_at' => optional($holding->updated_at)?->toIso8601String(),
            ])->all();

        return [['as_of' => now()->toDateString(), 'complete' => true], [
            'holding_id', 'stock_id', 'symbol', 'name', 'exchange', 'quantity', 'avg_buy_price',
            'invested_amount', 'owner_key', 'updated_at',
        ], $rows];
    }

    protected function transactions(PortfolioProfile $profile, array $options): array
    {
        $rows = Transaction::query()->with('stock:id,symbol,name,exchange')
            ->where('profile_id', $profile->id)->where('created_at', '<=', $options['_cutoff'])
            ->orderBy('transaction_date')->orderBy('id')->get()->map(fn (Transaction $transaction) => [
                'transaction_id' => $transaction->id,
                'transaction_date' => optional($transaction->transaction_date)?->toDateString(),
                'created_at' => optional($transaction->created_at)?->toIso8601String(),
                'stock_id' => $transaction->stock_id, 'symbol' => $transaction->stock?->symbol,
                'exchange' => $transaction->stock?->exchange, 'type' => $transaction->type,
                'quantity' => (float) $transaction->quantity, 'price' => (float) $transaction->price,
                'fees' => (float) $transaction->fees, 'source' => $transaction->source,
                'recommendation_id' => $transaction->recommendation_id, 'owner_key' => $transaction->owner_key,
            ])->all();

        return [['complete' => true], [
            'transaction_id', 'transaction_date', 'created_at', 'stock_id', 'symbol', 'exchange',
            'type', 'quantity', 'price', 'fees', 'source', 'recommendation_id', 'owner_key',
        ], $rows];
    }

    protected function portfolioValueHistory(PortfolioProfile $profile, array $options): array
    {
        $rows = PortfolioSnapshot::query()->where('profile_id', $profile->id)
            ->where('created_at', '<=', $options['_cutoff'])->orderBy('snapshot_date')->get()->map(fn (PortfolioSnapshot $snapshot) => [
                'snapshot_date' => optional($snapshot->snapshot_date)?->toDateString(),
                'portfolio_value' => (float) $snapshot->portfolio_value,
                'invested_value' => (float) $snapshot->invested_value,
                'created_at' => optional($snapshot->created_at)?->toIso8601String(),
            ])->all();

        return [['complete' => true], ['snapshot_date', 'portfolio_value', 'invested_value', 'created_at'], $rows];
    }

    protected function recommendations(PortfolioProfile $profile, array $options): array
    {
        $rows = TradingRecommendation::query()->with('security:id,symbol,name,exchange')
            ->where('profile_id', $profile->id)->where('created_at', '<=', $options['_cutoff'])
            ->orderBy('created_at')->orderBy('id')->get()->map(fn (TradingRecommendation $recommendation) => [
                'recommendation_id' => $recommendation->id,
                'created_at' => optional($recommendation->created_at)?->toIso8601String(),
                'security_id' => $recommendation->security_id, 'symbol' => $recommendation->security?->symbol,
                'action' => $recommendation->recommendation_type, 'status' => $recommendation->status,
                'reference_price' => $recommendation->reference_price !== null ? (float) $recommendation->reference_price : null,
                'strategy_id' => $recommendation->strategy_id,
                'artifact_version_id' => $recommendation->reusable_artifact_version_id,
            ])->all();

        return [['complete' => true], [
            'recommendation_id', 'created_at', 'security_id', 'symbol', 'action', 'status',
            'reference_price', 'strategy_id', 'artifact_version_id',
        ], $rows];
    }

    protected function ordersTrades(PortfolioProfile $profile, array $options): array
    {
        $rows = TradingOrder::query()->with('security:id,symbol,name,exchange')
            ->where('profile_id', $profile->id)->where('created_at', '<=', $options['_cutoff'])
            ->orderBy('created_at')->orderBy('id')->get()->map(fn (TradingOrder $order) => [
                'order_id' => $order->id, 'created_at' => optional($order->created_at)?->toIso8601String(),
                'security_id' => $order->security_id, 'symbol' => $order->security?->symbol,
                'side' => $order->side, 'quantity' => (float) $order->quantity,
                'order_type' => $order->order_type,
                'limit_price' => $order->limit_price !== null ? (float) $order->limit_price : null,
                'status' => $order->status, 'broker_status' => $order->broker_status,
                'filled_quantity' => $order->filled_quantity !== null ? (float) $order->filled_quantity : null,
                'average_fill_price' => $order->average_fill_price !== null ? (float) $order->average_fill_price : null,
                'executed_at' => optional($order->executed_at)?->toIso8601String(),
                'recommendation_id' => $order->recommendation_id,
                'artifact_version_id' => $order->reusable_artifact_version_id,
            ])->all();

        return [['complete' => true], [
            'order_id', 'created_at', 'security_id', 'symbol', 'side', 'quantity', 'order_type',
            'limit_price', 'status', 'broker_status', 'filled_quantity', 'average_fill_price',
            'executed_at', 'recommendation_id', 'artifact_version_id',
        ], $rows];
    }
}
