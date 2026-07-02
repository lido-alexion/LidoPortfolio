<?php

namespace App\Services;

use App\Support\ExternalHttp;

class BseEquityMasterService
{
    public const DEFAULT_LIST_API_URL = 'https://api.bseindia.com/BseIndiaAPI/api/ListofScripData/w?Group=&Scripcode=&industry=&segment=Equity&status=Active';

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string}>
     */
    public function fetchEquityRows(): array
    {
        $csvUrl = config('portfolio.stock_master.bse_equity_csv_url');
        if (is_string($csvUrl) && $csvUrl !== '') {
            return $this->parseCsvRows($this->download($csvUrl));
        }

        return $this->fetchFromApi();
    }

    public function listApiUrl(): string
    {
        $url = config('portfolio.stock_master.bse_list_api_url');

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return self::DEFAULT_LIST_API_URL;
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string}>
     */
    public function fetchFromApi(): array
    {
        $url = $this->listApiUrl();
        $response = ExternalHttp::client()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer' => 'https://www.bseindia.com/',
                'Origin' => 'https://www.bseindia.com',
                'Accept' => 'application/json, text/plain, */*',
            ])
            ->timeout(90)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download BSE equity master: HTTP '.$response->status());
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('BSE equity master response was not JSON.');
        }

        return $this->parseApiRows($payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{symbol: string, name: string, isin: ?string}>
     */
    public function parseApiRows(array $rows): array
    {
        $parsed = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mapped = $this->mapApiRow($row);
            if ($mapped === null) {
                continue;
            }

            $parsed[] = $mapped;
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{symbol: string, name: string, isin: ?string}|null
     */
    protected function mapApiRow(array $row): ?array
    {
        $status = strtoupper(trim((string) ($row['STATUS'] ?? $row['status'] ?? 'ACTIVE')));
        if ($status !== '' && $status !== 'ACTIVE') {
            return null;
        }

        $name = trim((string) ($row['scrip_name'] ?? $row['SCRIP_NAME'] ?? $row['Issuer Name'] ?? $row['Scrip_Name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $isin = trim((string) ($row['ISIN'] ?? $row['ISIN_NO'] ?? $row['isin_no'] ?? $row['ISIN No'] ?? ''));
        $isin = $isin !== '' ? strtoupper($isin) : null;

        $symbol = $this->resolveTradingSymbol($row);
        if ($symbol === null) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'name' => $name,
            'isin' => $isin,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveTradingSymbol(array $row): ?string
    {
        $candidates = [
            $row['scrip_id'] ?? null,
            $row['SCRIP_ID'] ?? null,
            $row['SecurityId'] ?? null,
            $row['SECURITY_ID'] ?? null,
            $row['NSDL_Symbol'] ?? null,
            $row['nsdl_symbol'] ?? null,
            $row['SYMBOL'] ?? null,
            $row['symbol'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $symbol = strtoupper(trim((string) $candidate));
            if ($symbol === '' || $this->looksLikeScripCodeOnly($symbol)) {
                continue;
            }

            if (preg_match('/^[A-Z0-9][A-Z0-9\-&]*$/', $symbol)) {
                return $symbol;
            }
        }

        return null;
    }

    protected function looksLikeScripCodeOnly(string $value): bool
    {
        return preg_match('/^\d{5,6}$/', $value) === 1;
    }

    /**
     * @return array<int, array{symbol: string, name: string, isin: ?string}>
     */
    public function parseCsvRows(string $content): array
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

        $symbolIdx = $headerMap['SECURITY ID'] ?? $headerMap['SYMBOL'] ?? $headerMap['SCRIP_ID'] ?? 0;
        $nameIdx = $headerMap['ISSUER NAME'] ?? $headerMap['SECURITY NAME'] ?? $headerMap['NAME'] ?? 1;
        $isinIdx = $headerMap['ISIN NO'] ?? $headerMap['ISIN'] ?? $headerMap['ISIN NUMBER'] ?? null;

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $symbol = strtoupper(trim($cols[$symbolIdx] ?? ''));
            if ($symbol === '' || $this->looksLikeScripCodeOnly($symbol)) {
                continue;
            }

            $name = trim($cols[$nameIdx] ?? $symbol);
            $isin = null;
            if ($isinIdx !== null) {
                $rawIsin = trim($cols[$isinIdx] ?? '');
                $isin = $rawIsin !== '' ? strtoupper($rawIsin) : null;
            }

            $rows[] = [
                'symbol' => $symbol,
                'name' => $name,
                'isin' => $isin,
            ];
        }

        return $rows;
    }

    protected function download(string $url): string
    {
        $response = ExternalHttp::client()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Referer' => 'https://www.bseindia.com/',
            ])
            ->timeout(60)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download BSE CSV: HTTP '.$response->status());
        }

        return (string) $response->body();
    }
}
