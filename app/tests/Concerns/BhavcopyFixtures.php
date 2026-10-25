<?php

namespace Tests\Concerns;

trait BhavcopyFixtures
{
    protected function bhavcopyCsv(string $exchange = 'NSE', string $date = '2026-10-26', array $rows = [[]]): string
    {
        $base = [
            'TradDt' => $date, 'BizDt' => $date, 'Sgmt' => 'CM', 'Src' => $exchange,
            'FinInstrmTp' => 'STK', 'FinInstrmId' => '1001', 'ISIN' => 'INE001A01010',
            'TckrSymb' => 'AAA', 'SctySrs' => $exchange === 'NSE' ? 'EQ' : 'B',
            'OpnPric' => '100', 'HghPric' => '105', 'LwPric' => '95', 'ClsPric' => '102',
            'TtlTradgVol' => '1000', 'SsnId' => 'F1',
        ];
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, array_keys($base), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values(array_replace($base, $row)), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    protected function bhavcopyZip(string $csv, string $date = '20261026', ?string $name = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bhavcopy_test_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString($name ?? 'BhavCopy_NSE_CM_0_0_0_'.$date.'_F_0000.csv', $csv);
        $zip->close();
        try {
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
