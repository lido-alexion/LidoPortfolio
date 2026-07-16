<?php

namespace App\Services;

use App\Support\ExternalHttp;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use RuntimeException;

class BseBhavcopyService
{
    public const BASE_URL = 'https://www.bseindia.com/';

    /**
     * Stream equity rows for one session day without loading the full CSV into memory.
     *
     * @param  callable(array{
     *   price_date: string,
     *   scrip_code: string,
     *   symbol: string,
     *   open_price: ?float,
     *   high_price: ?float,
     *   low_price: ?float,
     *   close_price: float,
     *   volume: ?int
     * }): void  $callback
     */
    public function eachEquityRowForDate(Carbon $date, callable $callback): void
    {
        $path = $this->downloadToTempFile($date);
        try {
            $this->streamUdiffCsvFile($path, $callback);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<int, array{
     *   price_date: string,
     *   scrip_code: string,
     *   symbol: string,
     *   open_price: ?float,
     *   high_price: ?float,
     *   low_price: ?float,
     *   close_price: float,
     *   volume: ?int
     * }>
     */
    public function fetchEquityRowsForDate(Carbon $date): array
    {
        $rows = [];
        $this->eachEquityRowForDate($date, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return $rows;
    }

    public function downloadUrlForDate(Carbon $date): string
    {
        $compact = $date->copy()->startOfDay()->format('Ymd');

        return self::BASE_URL.'download/BhavCopy/Equity/BhavCopy_BSE_CM_0_0_0_'.$compact.'_F_0000.CSV';
    }

    public function downloadForDate(Carbon $date): string
    {
        $path = $this->downloadToTempFile($date);
        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('BSE bhavcopy temp file could not be read.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    protected function downloadToTempFile(Carbon $date): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bse_bhav_');
        if ($path === false) {
            throw new RuntimeException('Unable to create temp file for BSE bhavcopy download.');
        }

        $response = ExternalHttp::client()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer' => 'https://www.bseindia.com/',
                'Origin' => 'https://www.bseindia.com',
                'Accept' => 'text/csv,text/plain,*/*',
            ])
            ->timeout(90)
            ->sink($path)
            ->get($this->downloadUrlForDate($date));

        if (! $response->successful()) {
            @unlink($path);
            throw new RuntimeException('BSE bhavcopy download failed: HTTP '.$response->status());
        }

        $sample = file_get_contents($path, false, null, 0, 8192) ?: '';
        if (! $this->looksLikeCsv($sample)) {
            @unlink($path);
            throw new RuntimeException('BSE bhavcopy response was not CSV (holiday or file not published yet)');
        }

        return $path;
    }

    public function looksLikeCsv(string $body): bool
    {
        $trimmed = ltrim($body);
        if ($trimmed === '' || str_starts_with($trimmed, '<')) {
            return false;
        }

        return str_contains($trimmed, 'TckrSymb') || str_contains($trimmed, 'FinInstrmId') || str_contains($trimmed, 'SC_CODE');
    }

    /**
     * @return array<int, array{
     *   price_date: string,
     *   scrip_code: string,
     *   symbol: string,
     *   open_price: ?float,
     *   high_price: ?float,
     *   low_price: ?float,
     *   close_price: float,
     *   volume: ?int
     * }>
     */
    public function parseUdiffCsv(string $body): array
    {
        $rows = [];
        $this->streamUdiffCsv($body, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return $rows;
    }

    /**
     * @param  callable(array{
     *   price_date: string,
     *   scrip_code: string,
     *   symbol: string,
     *   open_price: ?float,
     *   high_price: ?float,
     *   low_price: ?float,
     *   close_price: float,
     *   volume: ?int
     * }): void  $callback
     */
    public function streamUdiffCsv(string $body, callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bse_bhav_parse_');
        if ($path === false) {
            throw new RuntimeException('Unable to create temp file for BSE bhavcopy parse.');
        }

        try {
            file_put_contents($path, $body);
            unset($body);
            $this->streamUdiffCsvFile($path, $callback);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  callable(array{
     *   price_date: string,
     *   scrip_code: string,
     *   symbol: string,
     *   open_price: ?float,
     *   high_price: ?float,
     *   low_price: ?float,
     *   close_price: float,
     *   volume: ?int
     * }): void  $callback
     */
    public function streamUdiffCsvFile(string $path, callable $callback): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open BSE bhavcopy file for parsing.');
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || $header === []) {
            fclose($handle);

            return;
        }

        $map = [];
        foreach ($header as $index => $column) {
            $map[strtoupper(trim((string) $column))] = $index;
        }

        while (($cols = fgetcsv($handle)) !== false) {
            if (! is_array($cols) || $cols === []) {
                continue;
            }

            $mapped = $this->mapUdiffRow($cols, $map);
            if ($mapped !== null) {
                $callback($mapped);
            }
        }

        fclose($handle);
    }

    /**
     * @param  array<int, string>  $cols
     * @param  array<string, int>  $map
     * @return array{
     *   price_date: string,
     *   scrip_code: string,
     *   symbol: string,
     *   open_price: ?float,
     *   high_price: ?float,
     *   low_price: ?float,
     *   close_price: float,
     *   volume: ?int
     * }|null
     */
    protected function mapUdiffRow(array $cols, array $map): ?array
    {
        $dateRaw = $this->column($cols, $map, ['BIZDT', 'TRADDT', 'TRADE_DATE', 'DATE']);
        $symbol = strtoupper(trim($this->column($cols, $map, ['TCKRSYMB', 'SC_NAME', 'SYMBOL', 'SECURITY_ID']) ?? ''));
        $scripCode = trim($this->column($cols, $map, ['FININSTRMID', 'SC_CODE', 'SCRIP_CD', 'SCRIPCODE']) ?? '');
        $series = strtoupper(trim($this->column($cols, $map, ['SCTYSRS', 'SC_GROUP', 'SERIES']) ?? ''));

        if ($dateRaw === null || $dateRaw === '') {
            return null;
        }

        if ($series !== '' && ! in_array($series, ['EQ', 'A', 'B', 'T', 'X', 'XT', 'Z', 'P', 'IF'], true)) {
            return null;
        }

        $close = $this->numeric($this->column($cols, $map, ['CLSPRIC', 'CLOSE', 'CLOSE_PRICE']));
        if ($close === null || $close <= 0) {
            return null;
        }

        if ($symbol === '' && $scripCode === '') {
            return null;
        }

        try {
            $priceDate = Carbon::parse($dateRaw)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        if (! TradingCalendar::isEquitySessionDate(Carbon::parse($priceDate))) {
            return null;
        }

        return [
            'price_date' => $priceDate,
            'scrip_code' => $scripCode,
            'symbol' => $symbol,
            'open_price' => $this->numeric($this->column($cols, $map, ['OPNPRIC', 'OPEN', 'OPEN_PRICE'])),
            'high_price' => $this->numeric($this->column($cols, $map, ['HGHPRIC', 'HIGH', 'HIGH_PRICE'])),
            'low_price' => $this->numeric($this->column($cols, $map, ['LWPRIC', 'LOW', 'LOW_PRICE'])),
            'close_price' => $close,
            'volume' => $this->integer($this->column($cols, $map, ['TTLTRADGVOL', 'NO_OF_SHRS', 'VOLUME', 'TOTTRDQTY'])),
        ];
    }

    /**
     * @param  array<int, string>  $cols
     * @param  array<string, int>  $map
     * @param  array<int, string>  $candidates
     */
    protected function column(array $cols, array $map, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $index = $map[strtoupper($candidate)] ?? null;
            if ($index === null) {
                continue;
            }

            $value = trim((string) ($cols[$index] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function numeric(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '', $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    protected function integer(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '', $value);

        return is_numeric($normalized) ? (int) round((float) $normalized) : null;
    }
}
