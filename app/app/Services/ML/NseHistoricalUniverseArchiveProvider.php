<?php

namespace App\Services\ML;

use App\Contracts\MlHistoricalUniverseProvider;
use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Reconstructs dated NSE company-equity universes from immutable NSE exports.
 * This class intentionally has no current-universe fallback.
 */
class NseHistoricalUniverseArchiveProvider implements MlHistoricalUniverseProvider
{
    public const PARSER_VERSION = 'nse-pit-universe-parser-3';
    private const ALLOWED_SERIES = ['EQ', 'BE', 'BZ'];

    public function snapshotForDate(string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $file = $this->findSourceFile($date, (string) config('ml.historical_universe.mii_path', ''), 'nse_mii_security_file')
            ?? $this->findSourceFile($date, (string) config('ml.historical_universe.bhavcopy_path', ''), 'nse_cash_bhavcopy');
        if ($file === null) {
            $file = $this->acquireOfficialBhavcopy($date);
        }
        if ($file === null) {
            throw new MlHistoricalUniverseProviderException("NSE historical universe date unavailable: {$date}", true);
        }

        return $this->snapshotFile($file, $date);
    }

    /** Validated private staging entry point; never accepts an HTTP-supplied path. */
    public function stagedSnapshot(string $path, string $source, string $date, bool $requireMapping = true): array
    {
        $file = $this->findSourceFile($date, $path, $source);
        if ($file === null) {
            throw new MlHistoricalUniverseProviderException('Staged source unavailable.');
        }
        return $this->snapshotFile($file, $date, $requireMapping);
    }

    private function snapshotFile(array $file, string $date, bool $requireMapping = true): array
    {
        $parsed = $this->parseFile($file['path'], $file['source'], $date);
        $sourceSha256 = hash_file('sha256', $file['path']);
        if ($sourceSha256 === false) {
            throw new MlHistoricalUniverseProviderException('Unable to hash NSE source file.', true);
        }
        if ($parsed['members'] === []) {
            throw new MlHistoricalUniverseProviderException(
                "NSE historical universe source contains no eligible company-equity members: {$date}",
                false,
            );
        }
        $mapped = [];
        $unknown = [];
        $seen = [];
        foreach ($parsed['members'] as $member) {
            $stock = $this->resolve($member);
            if ($stock === null) {
                $unknown[] = $member['isin'] ?: $member['symbol'];
                continue;
            }
            if (isset($seen[$stock->id])) {
                $unknown[] = $member['isin'] ?: $member['symbol'];
                continue;
            }
            $seen[$stock->id] = true;
            $mapped[] = [
                'stock_id' => (int) $stock->id,
                'sector_snapshot' => $member['sector'] ?? null,
                'taxonomy_version' => $parsed['format_version'].';'.self::PARSER_VERSION,
                'classification_revision_hash' => hash('sha256', (string) ($member['sector'] ?? 'unknown')),
                'provider_symbol' => $member['symbol'],
                'exchange' => 'NSE',
            ];
        }

        $sourceCount = count($parsed['members']);
        $mappedCount = count($mapped);
        $percentage = round($mappedCount / $sourceCount * 100, 4);
        $diagnostics = [
            'requested_date' => $date,
            'effective_date' => $date,
            'source_validated_date' => $file['validated_date'],
            'source_date_basis' => $file['date_basis'],
            'nse_source_file' => $file['path'],
            'source_sha256' => $sourceSha256,
            'source' => $file['source'],
            'format_version' => $parsed['format_version'],
            'source_company_equity_member_count' => $sourceCount,
            'mapped_count' => $mappedCount,
            'unmapped_count' => count($unknown),
            'unmapped_identifiers' => array_values(array_unique(array_filter($unknown))),
            'mapping_percentage' => $percentage,
            'parser_version' => self::PARSER_VERSION,
        ];
        foreach (['archive_sha256', 'archive_url'] as $key) {
            if (isset($file[$key])) {
                $diagnostics[$key] = $file[$key];
            }
        }
        if ($requireMapping && $mappedCount * 10 < $sourceCount * 9) {
            throw new MlHistoricalUniverseProviderException(
                "NSE historical universe mapping below 90% for {$date} ({$percentage}%)",
                false,
                $diagnostics,
            );
        }

        return [
            'effective_from' => $date,
            'source' => $file['source'],
            'snapshot_key' => 'sha256:'.$sourceSha256,
            'response_version' => $parsed['format_version'].';'.self::PARSER_VERSION,
            'diagnostics' => $diagnostics,
            'memberships' => $mapped,
        ];
    }

    /**
     * Use the existing official NSE archive downloader when production has no
     * pre-staged file. The URL host is allowlisted and the downloaded archive
     * still passes the existing safe extraction/date/parser/mapping gates.
     *
     * @return array{path:string,source:string,validated_date:string,date_basis:string,archive_sha256:string,archive_url:string}|null
     */
    private function acquireOfficialBhavcopy(string $date): ?array
    {
        if (! config('forward_data.official_source_enabled', false)) {
            return null;
        }
        $base = rtrim((string) config('forward_data.official_source_base_url', ''), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (! in_array($host, (array) config('forward_data.official_source_allowed_hosts', []), true)) {
            throw new MlHistoricalUniverseProviderException('Official NSE source host is not allowlisted.', false);
        }

        $bootstrap = app(NseAcceptanceSourceBootstrapService::class);
        $descriptor = $bootstrap->descriptor($date, $base);
        $directory = (string) config('forward_data.official_source_directory', storage_path('app/private/forward-data/nse'));
        File::ensureDirectoryExists($directory, 0700, true);
        $archivePath = $directory.'/'.$descriptor['filename'];
        $csvName = preg_replace('/\.zip$/i', '', $descriptor['filename']) ?: ($descriptor['filename'].'.csv');
        $csvPath = $directory.'/'.$csvName;
        $archiveSha = null;

        if (! is_file($csvPath)) {
            $payload = $bootstrap->downloadOfficial($descriptor);
            $archiveSha = hash('sha256', $payload);
            $temporary = $archivePath.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                @unlink($temporary);
                throw new MlHistoricalUniverseProviderException('Official NSE archive could not be staged.', true);
            }
            chmod($temporary, 0400);
            rename($temporary, $archivePath);
            $contents = app(MlAcceptanceSourceService::class)->safeContents($archivePath, $descriptor['filename'], $date);
            $csvTemporary = $csvPath.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (file_put_contents($csvTemporary, $contents, LOCK_EX) !== strlen($contents)) {
                @unlink($csvTemporary);
                throw new MlHistoricalUniverseProviderException('Official NSE CSV could not be staged.', true);
            }
            chmod($csvTemporary, 0440);
            rename($csvTemporary, $csvPath);
        } elseif (is_file($archivePath)) {
            $archiveSha = hash_file('sha256', $archivePath) ?: null;
        }

        // Cached CSVs must pass the same date checks as newly staged evidence.
        $validated = $this->findSourceFile($date, $csvPath, 'nse_cash_bhavcopy');

        return [
            ...$validated,
            'archive_sha256' => $archiveSha ?: hash('sha256', (string) file_get_contents($csvPath)),
            'archive_url' => $descriptor['url'],
        ];
    }

    /** @return array{format_version:string,members:list<array{symbol:string,isin:?string,series:string,sector:?string}>} */
    public function parseLegacyBhavcopy(string $contents): array
    {
        return $this->parseDelimited($contents, 'legacy-bhavcopy');
    }

    /** @return array{format_version:string,members:list<array{symbol:string,isin:?string,series:string,sector:?string}>} */
    /** @return array{format_version:string,members:list<array{symbol:string,isin:?string,series:string,sector:?string}>} */
    public function parseUdiff(string $contents): array
    {
        return $this->parseDelimited($contents, 'udiff');
    }

    /** @return array{format_version:string,members:list<array{symbol:string,isin:?string,series:string,sector:?string}>} */
    public function parseMii(string $contents): array
    {
        return $this->parseDelimited($contents, 'mii-security-file');
    }

    /** @return array{path:string,source:string,validated_date:string,date_basis:string}|null */
    private function findSourceFile(string $date, string $configured, string $source): ?array
    {
        if ($configured === '') {
            return null;
        }
        $candidates = is_file($configured) ? [$configured] : (glob(rtrim($configured, '/').'/*') ?: []);
        $isSingleFile = is_file($configured);
        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }
            $filenameDates = $this->datesFromFilename(basename($path));
            if (! $isSingleFile && ! in_array($date, $filenameDates, true)) {
                if ($filenameDates !== []) {
                    continue;
                }
            }
            if ($isSingleFile && $filenameDates !== [] && ! in_array($date, $filenameDates, true)) {
                throw new MlHistoricalUniverseProviderException(
                    "NSE source filename date does not match requested date: {$path} ({$date})",
                    false,
                );
            }
            $filenameDate = in_array($date, $filenameDates, true) ? $date : ($filenameDates[0] ?? null);

            $contents = $this->readSourceContents($path);
            $contentDates = $this->datesFromContents($contents);
            if (! $isSingleFile && $filenameDates === [] && ($contentDates === [] || $contentDates[0] !== $date)) {
                continue;
            }
            if (count($contentDates) > 1) {
                throw new MlHistoricalUniverseProviderException(
                    "NSE source contains multiple trading dates and is not a single-session source: {$path}",
                    false,
                );
            }
            if ($contentDates !== [] && $contentDates[0] !== $date) {
                throw new MlHistoricalUniverseProviderException(
                    "NSE source content date does not match requested date: {$path} ({$date})",
                    false,
                );
            }
            if ($filenameDate !== null && $contentDates !== [] && $filenameDate !== $contentDates[0]) {
                throw new MlHistoricalUniverseProviderException(
                    "NSE source filename and content dates disagree: {$path}",
                    false,
                );
            }
            if ($filenameDates === [] && $contentDates === []) {
                if ($isSingleFile) {
                    throw new MlHistoricalUniverseProviderException(
                        "NSE source date cannot be proven from filename or contents: {$path}",
                        false,
                    );
                }
                continue;
            }
            return [
                'path' => $path,
                'source' => $source,
                'validated_date' => $contentDates[0] ?? $filenameDate,
                'date_basis' => $contentDates !== [] && $filenameDate !== null ? 'filename_and_content' : ($contentDates !== [] ? 'content' : 'filename'),
            ];
        }
        return null;
    }

    /** @return array{format_version:string,members:list<array{symbol:string,isin:?string,series:string,sector:?string}>} */
    private function parseFile(string $path, string $source, string $date): array
    {
        $contents = $this->readSourceContents($path);
        $format = $source === 'nse_mii_security_file'
            ? $this->parseMii($contents)
            : (preg_match('/trad(dt|ing.?date)|fininstrmid/i', substr($contents, 0, 1000))
                ? $this->parseUdiff($contents)
                : $this->parseLegacyBhavcopy($contents));
        if ($format['members'] === [] && trim($contents) !== '') {
            throw new MlHistoricalUniverseProviderException("NSE source contains no eligible company-equity rows: {$date}");
        }
        return $format;
    }

    private function readSourceContents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new MlHistoricalUniverseProviderException("Unable to read NSE source file: {$path}", true);
        }
        if (str_ends_with(strtolower($path), '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true || $zip->numFiles < 1) {
                throw new MlHistoricalUniverseProviderException("Unable to read NSE ZIP source: {$path}", true);
            }
            $contents = (string) $zip->getFromIndex(0);
            $zip->close();
        }
        return $contents;
    }

    /** @return list<string> */
    private function datesFromFilename(string $filename): array
    {
        $dates = [];
        preg_match_all('/(?<!\d)(\d{4}[-_.]?\d{2}[-_.]?\d{2}|\d{2}[-_.]?\d{2}[-_.]?\d{4}|\d{2}[A-Za-z]{3}\d{4})(?!\d)/', $filename, $matches);
        foreach ($matches[1] ?? [] as $raw) {
            $raw = strtoupper($raw);
            foreach (['Ymd', 'dmY', 'dMY'] as $format) {
                $normalized = str_replace(['-', '_', '.'], '', $raw);
                $candidate = $format === 'Ymd' ? substr($normalized, 0, 8) : $normalized;
                $date = $this->parseDateValue($candidate, $format);
                if ($date !== null) $dates[] = $date;
            }
        }
        return array_values(array_unique($dates));
    }

    /** @return list<string> */
    private function datesFromContents(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', trim($contents)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') return [];
        $delimiter = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';
        $headers = array_map(fn ($v) => $this->header((string) $v), str_getcsv(array_shift($lines), $delimiter));
        $index = array_flip($headers);
        $dateKey = $this->firstKey($index, ['trad dt', 'traddt', 'trading date', 'tradingdate', 'bizdt', 'date', 'timestamp']);
        if ($dateKey === null) return [];
        $dates = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $columns = str_getcsv($line, $delimiter);
            $value = trim((string) ($columns[$index[$dateKey]] ?? ''));
            $date = $this->parseDateValue($value);
            if ($date === null) throw new MlHistoricalUniverseProviderException('NSE source contains an invalid or missing content date.');
            $dates[] = $date;
        }
        return array_values(array_unique($dates));
    }

    public function validateEntryDate(string $filename, string $contents, string $requested): void
    {
        foreach ([...$this->datesFromFilename($filename), ...$this->datesFromContents($contents)] as $date) {
            if ($date !== $requested) throw new MlHistoricalUniverseProviderException('Archive entry date differs from requested date.');
        }
    }

    private function parseDateValue(string $value, ?string $format = null): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $formats = $format === null ? ['Ymd', 'Y-m-d', 'Y_m_d', 'Y.m.d', 'd-m-Y', 'd/m/Y', 'd_m_Y', 'd.m.Y', 'dMY', 'd-M-Y', 'd/M/Y'] : [$format];
        foreach ($formats as $candidateFormat) {
            $date = \DateTimeImmutable::createFromFormat('!'.$candidateFormat, strtoupper($value));
            if ($date !== false && strtoupper($date->format($candidateFormat)) === strtoupper($value)) return $date->format('Y-m-d');
        }
        return null;
    }

    /** @return array{format_version:string,members:list<array{symbol:string,isin:?string,series:string,sector:?string}>} */
    private function parseDelimited(string $contents, string $format): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (trim($contents) === '') {
            return ['format_version' => $format, 'members' => []];
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($contents)) ?: [];
        if ($lines === []) {
            return ['format_version' => $format, 'members' => []];
        }
        $delimiter = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';
        $header = array_map(fn ($v) => $this->header((string) $v), str_getcsv(array_shift($lines), $delimiter));
        $index = array_flip($header);
        $symbolKey = $this->firstKey($index, ['symbol', 'ticker', 'tradingsymbol', 'tckrsymb']);
        $seriesKey = $this->firstKey($index, ['series', 'scty srs', 'sctysrs', 'securityseries']);
        $isinKey = $this->firstKey($index, ['isin', 'isin number', 'isinno', 'isinno']);
        if ($symbolKey === null || $seriesKey === null) {
            throw new MlHistoricalUniverseProviderException("NSE {$format} header is missing symbol/series columns");
        }
        $sectorKey = $this->firstKey($index, ['sector', 'industry']);
        $members = [];
        $priority = ['EQ' => 1, 'BE' => 2, 'BZ' => 3];
        $dedupe = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $columns = str_getcsv($line, $delimiter);
            $symbol = strtoupper(trim((string) ($columns[$index[$symbolKey]] ?? '')));
            $series = strtoupper(trim((string) ($columns[$index[$seriesKey]] ?? '')));
            $isin = $isinKey !== null ? strtoupper(trim((string) ($columns[$index[$isinKey]] ?? ''))) : '';
            if ($symbol === '' || ! in_array($series, self::ALLOWED_SERIES, true) || str_starts_with($isin, 'INF')) continue;
            $key = $isin !== '' ? 'I:'.$isin : 'S:'.$symbol;
            $row = ['symbol' => $symbol, 'isin' => $isin !== '' ? $isin : null, 'series' => $series, 'sector' => $sectorKey !== null ? trim((string) ($columns[$index[$sectorKey]] ?? '')) ?: null : null];
            if (! isset($dedupe[$key]) || $priority[$series] < $priority[$dedupe[$key]['series']]) $dedupe[$key] = $row;
        }
        foreach ($dedupe as $member) $members[] = $member;
        return ['format_version' => $format, 'members' => $members];
    }

    private function header(string $header): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['"', "'"], '', $header)) ?? ''));
    }

    private function firstKey(array $index, array $keys): ?string
    {
        foreach ($keys as $key) if (isset($index[$key])) return $key;
        return null;
    }

    private function resolve(array $member): ?Stock
    {
        $query = Stock::query()->where('exchange', 'NSE')->where('is_benchmark', false);
        if ($member['isin']) {
            $stock = (clone $query)->whereRaw('UPPER(isin) = ?', [$member['isin']])->first();
            if ($stock) return $stock;
        }
        $stock = $query->whereRaw('UPPER(symbol) = ?', [$member['symbol']])->first();
        // A reused symbol cannot override a conflicting stable identity.
        if ($stock !== null && $member['isin'] && trim((string) $stock->isin) !== ''
            && strtoupper(trim($stock->isin)) !== $member['isin']) {
            return null;
        }
        return $stock;
    }
}
