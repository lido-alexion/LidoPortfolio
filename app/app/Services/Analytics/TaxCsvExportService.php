<?php

namespace App\Services\Analytics;

use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TaxCsvExportService
{
    public const SCHEMA_VERSION = 'stox.tax-export.v1';

    public function __construct(private AccountTaxReportService $reports) {}

    /** @param array<int, int>|null $portfolioIds */
    public function response(User $user, string $dataset, string $financialYear, ?array $portfolioIds): StreamedResponse
    {
        $cutoff = now();
        $report = $this->reports->calculate($user, $financialYear, $portfolioIds, $cutoff->toDateTimeString());
        [$headers, $rows] = match ($dataset) {
            'realized_gains' => [[
                'stock_id', 'disposal_id', 'lot_source_id', 'acquired_on', 'disposed_on', 'quantity',
                'cost_basis', 'proceeds', 'gain', 'term', 'holding_days',
            ], $report['realized_disposals']],
            'open_lots' => [[
                'stock_id', 'source_id', 'origin', 'acquired_on', 'original_quantity', 'remaining_quantity', 'unit_cost',
            ], $report['open_lots_informational']],
            'dividends' => [['id', 'stock_id', 'received_on', 'amount', 'currency', 'source', 'source_reference'],
                collect($report['dividends'])->map(fn ($row) => $row->toArray())->all()],
            'losses' => [['id', 'financial_year', 'loss_type', 'amount', 'status', 'external_reference', 'reason'],
                collect($report['losses'])->map(fn ($row) => $row->toArray())->all()],
            'summary' => [['short_term_realized_gain', 'long_term_realized_gain', 'dividend_income', 'estimated_tax'],
                [$report['summary']]],
            'assumptions' => [['jurisdiction', 'lot_method', 'canonical_accounting_method', 'long_term_holding_days', 'tax_advice', 'limitations'], [[
                ...$report['assumptions'],
                'limitations' => json_encode($report['limitations'], JSON_UNESCAPED_SLASHES),
            ]]],
        };
        $filename = 'stox-tax-'.$dataset.'-'.$financialYear.'-'.$cutoff->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $dataset, $financialYear, $cutoff, $report, $headers, $rows) {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['schema_version', self::SCHEMA_VERSION]);
            fputcsv($stream, ['dataset', $dataset]);
            fputcsv($stream, ['account_id', $user->id]);
            fputcsv($stream, ['financial_year', $financialYear]);
            fputcsv($stream, ['calculation_mode', $report['calculation_mode']]);
            fputcsv($stream, ['portfolio_ids', implode('|', $report['portfolio_ids'])]);
            fputcsv($stream, ['currency', 'INR']);
            fputcsv($stream, ['completeness', $report['completeness']]);
            fputcsv($stream, ['request_cutoff', $cutoff->toIso8601String()]);
            fputcsv($stream, []);
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn (string $key) => $row[$key] ?? null, $headers));
            }
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
