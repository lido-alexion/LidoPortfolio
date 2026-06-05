<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\ExternalHttp;
class StockMasterSyncService
{
    public function __construct(
        protected ProviderResolverService $resolver,
        protected PortfolioLoggerService $portfolioLogger,
    ) {}

    /**
     * @return array{added: int, updated: int, deactivated: int, skipped: int, source: string}
     */
    public function syncStockMaster(): array
    {
        $stats = [
            'added' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'source' => 'nse',
        ];

        $this->portfolioLogger->scheduler('info', 'Stock master sync started', [
            'start_time' => now()->toIso8601String(),
        ]);

        $rows = $this->fetchNseEquityRows();
        $seen = [];

        foreach ($rows as $row) {
            try {
                $normalized = $this->resolver->normalizeSymbol($row['symbol'], 'NSE');
            } catch (\InvalidArgumentException) {
                $stats['skipped']++;
                continue;
            }

            $key = $normalized['symbol'].'|'.$normalized['exchange'];
            if (isset($seen[$key])) {
                $this->portfolioLogger->validation('warning', 'Duplicate symbol conflict during stock master sync', [
                    'symbol' => $normalized['symbol'],
                    'exchange' => $normalized['exchange'],
                ]);
                $stats['skipped']++;

                continue;
            }
            $seen[$key] = true;

            $result = $this->upsertMasterRow(
                $normalized['symbol'],
                $normalized['exchange'],
                $row['name'],
                $row['isin'] ?? null,
                $row['series'] ?? null,
            );

            $stats[$result]++;
        }

        $stats['deactivated'] = $this->deactivateMissing(array_keys($seen), 'NSE');

        if (config('portfolio.stock_master.bse_enabled')) {
            $bseStats = $this->syncBseMaster();
            $stats['added'] += $bseStats['added'];
            $stats['updated'] += $bseStats['updated'];
            $stats['deactivated'] += $bseStats['deactivated'];
            $stats['skipped'] += $bseStats['skipped'];
            $stats['source'] = 'nse+bse';
        }

        $this->portfolioLogger->scheduler('info', 'Stock master sync completed', [
            'end_time' => now()->toIso8601String(),
            'added' => $stats['added'],
            'updated' => $stats['updated'],
            'deactivated' => $stats['deactivated'],
            'skipped' => $stats['skipped'],
        ]);

        return $stats;
    }

    /**
     * @return array{added: int, updated: int, deactivated: int, skipped: int}
     */
    public function syncBseMaster(): array
    {
        $url = config('portfolio.stock_master.bse_equity_csv_url');
        if (! $url) {
            $this->portfolioLogger->scheduler('info', 'BSE stock master sync skipped (not configured)');

            return ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0];
        }

        $stats = ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0];
        $rows = $this->downloadCsvRows($url);
        $seen = [];

        foreach ($rows as $row) {
            $symbol = strtoupper(trim($row['symbol'] ?? ''));
            if ($symbol === '') {
                $stats['skipped']++;
                continue;
            }

            $key = $symbol.'|BSE';
            $seen[$key] = true;
            $result = $this->upsertMasterRow($symbol, 'BSE', $row['name'] ?? $symbol, $row['isin'] ?? null, null);
            $stats[$result]++;
        }

        $stats['deactivated'] = $this->deactivateMissing(array_keys($seen), 'BSE');

        return $stats;
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string, series: ?string}>
     */
    public function fetchNseEquityRows(): array
    {
        $url = config('portfolio.stock_master.nse_equity_csv_url');
        $content = $this->downloadCsv($url);

        return $this->parseNseEquityCsv($content);
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string, series: ?string}>
     */
    public function parseNseEquityCsv(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($header as $index => $column) {
            $headerMap[strtoupper(trim($column))] = $index;
        }

        $symbolIdx = $headerMap['SYMBOL'] ?? 0;
        $nameIdx = $headerMap['NAME OF COMPANY'] ?? 1;
        $seriesIdx = $headerMap['SERIES'] ?? 2;
        $isinIdx = $headerMap['ISIN NUMBER'] ?? $headerMap['ISIN'] ?? null;

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $series = strtoupper(trim($cols[$seriesIdx] ?? 'EQ'));
            if ($series !== 'EQ') {
                continue;
            }

            $symbol = strtoupper(trim($cols[$symbolIdx] ?? ''));
            if ($symbol === '' || $this->resolver->isMalformed($symbol)) {
                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'name' => trim($cols[$nameIdx] ?? $symbol),
                'isin' => $isinIdx !== null ? trim($cols[$isinIdx] ?? '') ?: null : null,
                'series' => $series,
            ];
        }

        return $rows;
    }

    protected function upsertMasterRow(
        string $symbol,
        string $exchange,
        string $name,
        ?string $isin,
        ?string $series,
    ): string {
        $stock = Stock::query()->firstOrNew([
            'symbol' => $symbol,
            'exchange' => $exchange,
            'is_benchmark' => false,
        ]);

        $isNew = ! $stock->exists;
        $stock->name = $name;
        $stock->isin = $isin ?: $stock->isin;
        $stock->is_active = true;
        $this->resolver->applyProviderSymbols($stock);

        if (! $stock->last_verified_at) {
            $stock->last_verified_at = now();
        }

        $stock->save();

        return $isNew ? 'added' : 'updated';
    }

    /**
     * @param  array<int, string>  $seenKeys
     */
    protected function deactivateMissing(array $seenKeys, string $exchange): int
    {
        $seenSymbols = collect($seenKeys)
            ->map(fn (string $key) => explode('|', $key)[0])
            ->unique()
            ->values()
            ->all();

        if ($seenSymbols === []) {
            return 0;
        }

        return Stock::query()
            ->where('exchange', $exchange)
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereNotIn('symbol', $seenSymbols)
            ->update(['is_active' => false]);
    }

    protected function downloadCsv(string $url): string
    {
        $response = ExternalHttp::client()
            ->timeout(60)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download stock master CSV: HTTP '.$response->status());
        }

        return (string) $response->body();
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string}>
     */
    protected function downloadCsvRows(string $url): array
    {
        $content = $this->downloadCsv($url);
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        array_shift($lines);

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                continue;
            }
            $rows[] = [
                'symbol' => strtoupper(trim($cols[0])),
                'name' => trim($cols[1]),
                'isin' => isset($cols[2]) ? trim($cols[2]) ?: null : null,
            ];
        }

        return $rows;
    }
}
