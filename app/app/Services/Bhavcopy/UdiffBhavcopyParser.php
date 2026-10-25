<?php

namespace App\Services\Bhavcopy;

use Carbon\Carbon;
use RuntimeException;

/** Validates the entire final cash-market file before its rows reach a writer. */
class UdiffBhavcopyParser
{
    public function parse(string $csv, Carbon $date, string $exchange): array
    {
        if (strlen($csv) > 32 * 1024 * 1024) {
            throw new RuntimeException('Bhavcopy CSV exceeds the daily file limit.');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open bhavcopy parser stream.');
        }
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $header = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($header)) {
                throw new RuntimeException('Bhavcopy CSV header is missing.');
            }
            $header = array_map(fn ($v) => strtoupper(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $v))), $header);
            if (count(array_unique($header)) !== count($header)) {
                throw new RuntimeException('Bhavcopy CSV has duplicate columns.');
            }
            $required = ['TRADDT', 'SGMT', 'SRC', 'FININSTRMTP', 'FININSTRMID', 'ISIN',
                'TCKRSYMB', 'SCTYSRS', 'OPNPRIC', 'HGHPRIC', 'LWPRIC', 'CLSPRIC', 'TTLTRADGVOL', 'SSNID'];
            if (array_diff($required, $header) !== []) {
                throw new RuntimeException('Bhavcopy CSV schema is not a final UDiFF cash-market file.');
            }
            $rows = [];
            $seen = [];
            $total = $invalid = $excluded = 0;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (++$total > 100000 || count($values) !== count($header)) {
                    throw new RuntimeException('Bhavcopy CSV is truncated or exceeds the daily row limit.');
                }
                $raw = array_combine($header, array_map(fn ($v) => trim((string) $v), $values));
                if ($raw['TRADDT'] !== $date->toDateString()
                    || strtoupper($raw['SRC']) !== $exchange || strtoupper($raw['SGMT']) !== 'CM') {
                    throw new RuntimeException('Bhavcopy trading date, exchange or segment does not match the request.');
                }
                if (strtoupper($raw['FININSTRMTP']) !== 'STK' || strtoupper($raw['SSNID']) !== 'F1') {
                    $excluded++;
                    continue;
                }
                $row = $this->equityRow($raw, $date);
                if ($row === null) {
                    $invalid++;
                    continue;
                }
                $key = $row['scrip_code'].'|'.$row['series'];
                if (isset($seen[$key])) {
                    if ($seen[$key] !== $row) {
                        throw new RuntimeException('Bhavcopy contains conflicting rows for one security.');
                    }
                    continue;
                }
                $seen[$key] = $row;
                $rows[] = $row;
            }
            if ($rows === []) {
                throw new RuntimeException('Bhavcopy contains no usable equity OHLCV rows.');
            }

            return ['rows' => $rows, 'source_rows' => $total, 'invalid_rows' => $invalid, 'excluded_rows' => $excluded];
        } finally {
            fclose($stream);
        }
    }

    private function equityRow(array $raw, Carbon $date): ?array
    {
        $symbol = strtoupper($raw['TCKRSYMB']);
        $isin = strtoupper($raw['ISIN']);
        $series = strtoupper($raw['SCTYSRS']);
        if ($symbol === '' || $series === '' || ! ctype_digit($raw['FININSTRMID'])
            || ($isin !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin) !== 1)) {
            return null;
        }
        $numbers = [];
        foreach (['OPNPRIC', 'HGHPRIC', 'LWPRIC', 'CLSPRIC', 'TTLTRADGVOL'] as $key) {
            $value = str_replace(',', '', $raw[$key]);
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return null;
            }
            $numbers[$key] = (float) $value;
        }
        [$open, $high, $low, $close, $volume] = array_values($numbers);
        if (min($open, $high, $low, $close) <= 0 || $high < max($open, $low, $close)
            || $low > min($open, $high, $close) || $volume < 0 || $volume >= PHP_INT_MAX || floor($volume) !== $volume) {
            return null;
        }

        return [
            'price_date' => $date->toDateString(), 'symbol' => $symbol, 'isin' => $isin,
            'series' => $series, 'scrip_code' => $raw['FININSTRMID'],
            'open_price' => $open, 'high_price' => $high, 'low_price' => $low,
            'close_price' => $close, 'volume' => (int) $volume,
        ];
    }
}
