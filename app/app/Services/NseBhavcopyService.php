<?php

namespace App\Services;

use App\Services\Bhavcopy\UdiffBhavcopyParser;
use App\Support\ExternalHttp;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use RuntimeException;
use ZipArchive;

class NseBhavcopyService
{
    public function __construct(protected UdiffBhavcopyParser $parser) {}

    public function downloadUrlForDate(Carbon $date): string
    {
        return 'https://nsearchives.nseindia.com/content/cm/'.$this->csvName($date).'.zip';
    }

    public function fetchDailyRows(Carbon $date): array
    {
        if (! TradingCalendar::isEquitySessionDate($date)) {
            return ['rows' => [], 'source_rows' => 0, 'invalid_rows' => 0, 'excluded_rows' => 0];
        }
        $response = ExternalHttp::client()->withHeaders([
            'User-Agent' => 'StoX-daily-market-data/1.0',
            'Accept' => 'application/zip',
            'Referer' => 'https://www.nseindia.com/',
        ])->timeout(45)->get($this->downloadUrlForDate($date));
        if (! $response->successful()) {
            throw new RuntimeException('NSE daily bhavcopy download failed: HTTP '.$response->status());
        }
        $body = $response->body();
        if (strlen($body) > 8 * 1024 * 1024 || ! str_starts_with($body, "PK")) {
            throw new RuntimeException('NSE daily bhavcopy is not a supported ZIP archive.');
        }
        $path = tempnam(sys_get_temp_dir(), 'nse_daily_bhav_');
        if ($path === false) {
            throw new RuntimeException('Cannot create NSE bhavcopy temporary file.');
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            file_put_contents($path, $body);
            unset($body);
            if ($zip->open($path) !== true) {
                throw new RuntimeException('NSE daily bhavcopy ZIP is invalid.');
            }
            $opened = true;
            $index = null;
            $csvCount = 0;
            if ($zip->numFiles > 8) {
                throw new RuntimeException('NSE daily bhavcopy ZIP contains unexpected files.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false || ! str_ends_with(strtolower($entry['name']), '.csv')) {
                    continue;
                }
                $csvCount++;
                if ($entry['name'] !== $this->csvName($date) || $entry['size'] > 32 * 1024 * 1024) {
                    throw new RuntimeException('NSE daily bhavcopy ZIP entry does not match the requested session.');
                }
                $index = $i;
            }
            if ($csvCount !== 1 || $index === null) {
                throw new RuntimeException('NSE daily bhavcopy ZIP must contain exactly one session CSV.');
            }

            $csvStream = $zip->getStream($this->csvName($date));
            if ($csvStream === false) {
                throw new RuntimeException('NSE daily bhavcopy ZIP session CSV could not be read.');
            }
            try {
                // Read at most one byte beyond the parser limit so a malicious
                // archive cannot force unbounded decompression allocation.
                $csv = stream_get_contents($csvStream, 32 * 1024 * 1024 + 1);
            } finally {
                fclose($csvStream);
            }
            if ($csv === false || strlen($csv) > 32 * 1024 * 1024) {
                throw new RuntimeException('NSE daily bhavcopy CSV exceeds the daily file limit.');
            }
            $parsed = $this->parser->parse($csv, $date, 'NSE');

            // Fingerprint validated session content, not ZIP envelope metadata
            // or compression choices. This remains evidence only and cannot
            // override parser or identity validation.
            return $parsed + ['source_revision_sha256' => hash('sha256', $csv)];
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    private function csvName(Carbon $date): string
    {
        return 'BhavCopy_NSE_CM_0_0_0_'.$date->format('Ymd').'_F_0000.csv';
    }
}
