<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/** Verifies retained official source bytes before staging human-review evidence for issue #383. */
class BnagrochemF042EvidenceStager
{
    private const BHAVCOPY_CSV_SHA256 = '5736864df9797ba7b0427ec91dc506b5cd07c283edaefa8cf3f5609da4720b9e';
    private const BHAVCOPY_MEMBER = 'BhavCopy_NSE_CM_0_0_0_20261113_F_0000.csv';

    public function __construct(private DataQualityIssueService $issues) {}

    /**
     * Discover the single retained historical event through the normal DQ issue
     * service. The stock row lock serializes concurrent first discoveries;
     * evidence verification and issue creation/staging commit atomically.
     *
     * @param array{listing_circular:UploadedFile,clearing_circular:UploadedFile,bhavcopy_archive:UploadedFile} $files
     */
    public function discoverBnagrochemHistorical(array $files): DataQualityIssue
    {
        // Verify bytes before taking database locks or creating any issue.
        $payloads = $this->verifiedPayloads($files);
        $runId = DataQualityIssueService::newDetectionRunId('data-quality:discover-official-history');

        return DB::transaction(function () use ($payloads, $runId, $files): DataQualityIssue {
            $stock = \App\Models\Stock::query()->whereKey(7096)->lockForUpdate()->first();
            if ($stock === null || $stock->symbol !== 'BNAGROCHEM' || $stock->exchange !== 'NSE'
                || $stock->isin !== 'INE00HZ01029') {
                throw new DomainException('The configured BNAGROCHEM listing identity is unavailable.', 'DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', 422);
            }

            $transition = config('universe_nse_price_identities.transitions.CML77412');
            if (($transition['symbol'] ?? null) !== 'BNAGROCHEM'
                || ($transition['old_isin'] ?? null) !== 'INE00HZ01011'
                || ($transition['new_isin'] ?? null) !== $stock->isin
                || ($transition['effective_on'] ?? null) !== '2026-11-16'
                || (float) ($transition['price_divisor'] ?? 0) !== 10.0) {
                throw new DomainException('The retained official identity transition does not match the bounded discovery.', 'DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', 422);
            }

            $matches = DataQualityIssue::query()->where('stock_id', $stock->id)
                ->where('issue_type', DataQualityIssue::TYPE_CORPORATE_ACTION)
                ->whereDate('ex_date', '2026-11-16')->lockForUpdate()->get();
            if ($matches->count() > 1) {
                throw new DomainException('Multiple historical corporate-action records exist for this event; discovery stopped for manual reconciliation.', 'DATA_QUALITY_SOURCE_EVIDENCE_CONFLICT', 409);
            }
            $prior = $matches->first();
            if ($prior !== null && $prior->detection_method !== DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED) {
                throw new DomainException('Historical discovery cannot merge with a non-exchange-feed event record.', 'DATA_QUALITY_SOURCE_EVIDENCE_CONFLICT', 409);
            }
            if ($prior !== null && $prior->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW) {
                throw new DomainException('Historical discovery cannot reopen a resolved corporate-action issue.', 'DATA_QUALITY_STALE_RESOLUTION', 409);
            }
            if ($prior !== null && ($prior->corporate_action_type !== 'split'
                || abs((float) $prior->suggested_ratio - 10.0) >= 0.000001)) {
                throw new DomainException('Existing pending issue differs from the verified historical action.', 'DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', 422);
            }

            $ratioEvidence = [[
                'evidence_key' => 'exchange_ratio',
                'evidence_label' => 'Official historical NSE corporate-action discovery',
                'evidence_value' => '1:10',
                'evidence_payload' => [
                    'source' => 'official_historical_discovery',
                    'transition_document' => 'CML77412',
                    'corroborating_document' => 'CMPT77438',
                    'historical_trade_date' => '2026-11-13',
                    'historical_isin' => 'INE00HZ01011',
                    'current_isin' => 'INE00HZ01029',
                    'ex_date' => '2026-11-16',
                    'ratio' => '1:10',
                    'source_bundle_sha256' => hash('sha256', implode('', array_map(
                        fn (array $payload): string => (string) ($payload['sha256'] ?? $payload['source_archive_sha256'] ?? ''),
                        array_values($payloads),
                    ))),
                ],
                'captured_at' => now(),
            ]];
            $auditRows = $prior?->evidences()->where('evidence_key', 'exchange_ratio')
                ->where('evidence_payload->source', 'official_historical_discovery')->get() ?? collect();
            if ($auditRows->count() > 1) {
                throw new DomainException('Duplicate historical discovery audit evidence exists; discovery stopped for manual reconciliation.', 'DATA_QUALITY_SOURCE_EVIDENCE_CONFLICT', 409);
            }
            if ($auditRows->isNotEmpty()) {
                // Run IDs differ between attempts and are attached by the DQ service,
                // so compare the immutable source identity after removing that metadata.
                $actual = $auditRows->first()->evidence_payload ?? [];
                $expected = $ratioEvidence[0]['evidence_payload'];
                unset($actual['detection_run_id'], $actual['detection_method']);
                if (! $this->samePayload($actual, $expected)) {
                    throw new DomainException('Existing historical discovery audit evidence differs from the verified bundle.', 'DATA_QUALITY_SOURCE_EVIDENCE_CONFLICT', 409);
                }
            }
            $hasDiscoveryAudit = $auditRows->isNotEmpty();
            $issue = $this->issues->createOrRefreshPendingIssueForStock(
                $stock,
                DataQualityIssue::TYPE_CORPORATE_ACTION,
                DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
                [
                    'detection_source' => 'official_historical_discovery',
                    'corporate_action_type' => 'split',
                    'suggested_ratio' => 10.0,
                    'latest_suggested_ratio' => 10.0,
                    'ex_date' => '2026-11-16',
                    'raw_payload' => ['source' => 'official_historical_discovery', 'transition' => 'CML77412'],
                    'detection_payload' => ['detector' => 'official_historical_discovery', 'transition' => 'CML77412'],
                    'exchange_match' => true,
                    'confidence' => 1.0,
                    'detected_at' => now(),
                ],
                $hasDiscoveryAudit ? [] : $ratioEvidence,
                $runId,
            );

            // Reuse the same idempotent stager and exact evidence contract as
            // the existing authenticated issue-level staging endpoint.
            return $this->stage($issue, $files);
        }, 3);
    }

    /** @param array{listing_circular:UploadedFile,clearing_circular:UploadedFile,bhavcopy_archive:UploadedFile} $files */
    public function stage(DataQualityIssue $issue, array $files): DataQualityIssue
    {
        $payloads = $this->verifiedPayloads($files);

        return DB::transaction(function () use ($issue, $payloads): DataQualityIssue {
            $locked = DataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            $stock = \App\Models\Stock::query()->whereKey($locked->stock_id)->lockForUpdate()->first();
            $this->assertEligibleIssue($locked, $stock);

            $keys = array_keys($payloads);
            $existing = DataQualityIssueEvidence::query()->where('issue_id', $locked->id)
                ->whereIn('evidence_key', $keys)->orderBy('id')->get()->groupBy('evidence_key');
            foreach ($payloads as $key => $payload) {
                $rows = $existing->get($key, collect());
                if ($rows->isEmpty()) continue;
                if ($rows->count() !== 1 || ! $this->samePayload($rows->first()->evidence_payload ?? [], $payload)) {
                    throw new DomainException(
                        'Existing official evidence differs from the verified source bundle; evidence was not overwritten.',
                        'DATA_QUALITY_SOURCE_EVIDENCE_CONFLICT', 409,
                    );
                }
            }

            $missing = [];
            foreach ($payloads as $key => $payload) {
                if ($existing->has($key)) continue;
                $missing[] = [
                    'evidence_key' => $key,
                    'evidence_label' => $this->labelFor($key),
                    'evidence_value' => $this->hashFor($key, $payload),
                    'evidence_payload' => $payload,
                    'captured_at' => now(),
                ];
            }
            if ($missing !== []) $this->issues->attachEvidence($locked, $missing);

            return $locked->fresh(['stock', 'evidences']);
        });
    }

    /** @param array<string,UploadedFile> $files @return array<string,array<string,mixed>> */
    private function verifiedPayloads(array $files): array
    {
        $catalogue = config('universe_nse_price_identities');
        $transition = $catalogue['transitions']['CML77412'] ?? [];
        $evidence = collect($transition['evidence'] ?? []);
        $listing = $evidence->firstWhere('document', 'CML77412');
        $clearing = $evidence->firstWhere('document', 'CMPT77438');
        $identity = collect($catalogue['identities'] ?? [])->first(fn (array $row): bool =>
            ($row['symbol'] ?? null) === 'BNAGROCHEM'
            && ($row['historical_isin'] ?? null) === 'INE00HZ01011'
            && ($row['canonical_isin'] ?? null) === 'INE00HZ01029'
            && ($row['valid_from'] ?? null) === '2026-11-13'
            && ($row['valid_until'] ?? null) === '2026-11-15'
        );
        $archive = collect($identity['evidence'] ?? [])->firstWhere('role', 'historical_observation');

        if (! is_array($listing) || ! is_array($clearing) || ! is_array($archive)) {
            $this->invalidBundle();
        }

        $listingBytes = $this->readBounded($files['listing_circular'], 512 * 1024);
        $clearingBytes = $this->readBounded($files['clearing_circular'], 512 * 1024);
        $archiveBytes = $this->readBounded($files['bhavcopy_archive'], 2 * 1024 * 1024);
        if (! str_starts_with($listingBytes, '%PDF-') || ! str_starts_with($clearingBytes, '%PDF-')
            || ! hash_equals((string) $listing['sha256'], hash('sha256', $listingBytes))
            || ! hash_equals((string) $clearing['sha256'], hash('sha256', $clearingBytes))
            || ! hash_equals((string) $archive['sha256'], hash('sha256', $archiveBytes))) {
            $this->invalidBundle();
        }

        $row = $this->verifiedBhavcopyRow($files['bhavcopy_archive']);
        $listingPayload = [
            'document' => 'CML77412', 'url' => $listing['url'], 'sha256' => $listing['sha256'],
            'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'new_isin' => 'INE00HZ01029', 'ex_date' => '2026-11-16',
        ];
        $clearingPayload = [
            'document' => 'CMPT77438', 'url' => $clearing['url'], 'sha256' => $clearing['sha256'],
            'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'ex_date' => '2026-11-16',
        ];
        $bhavcopyPayload = [
            'source_url' => $archive['url'], 'source_archive_sha256' => $archive['sha256'],
            'source_csv_sha256' => self::BHAVCOPY_CSV_SHA256, 'trade_date' => '2026-11-13',
            'symbol' => 'BNAGROCHEM', 'isin' => 'INE00HZ01011', 'instrument_id' => 762608,
            'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'], 'close' => $row['close'], 'volume' => $row['volume'],
        ];

        return [
            'bnagrochem_listing_circular' => $listingPayload,
            'bnagrochem_clearing_circular' => $clearingPayload,
            'bnagrochem_bhavcopy' => $bhavcopyPayload,
        ];
    }

    /** @return array{open:float,high:float,low:float,close:float,volume:int} */
    private function verifiedBhavcopyRow(UploadedFile $archiveFile): array
    {
        if (! class_exists(ZipArchive::class)) $this->invalidBundle();
        $zip = new ZipArchive();
        if ($zip->open($archiveFile->getRealPath(), ZipArchive::CHECKCONS) !== true
            || $zip->numFiles !== 1 || $zip->getNameIndex(0) !== self::BHAVCOPY_MEMBER
            || $zip->statIndex(0)['size'] > 2 * 1024 * 1024) {
            $this->invalidBundle();
        }
        $csv = $zip->getFromIndex(0);
        $zip->close();
        if (! is_string($csv) || ! hash_equals(self::BHAVCOPY_CSV_SHA256, hash('sha256', $csv))) $this->invalidBundle();

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (! is_array($headers)) $this->invalidBundle();
        $matches = [];
        while (($values = fgetcsv($stream)) !== false) {
            if (count($values) !== count($headers)) continue;
            $entry = array_combine($headers, $values);
            if (($entry['TradDt'] ?? null) === '2026-11-13'
                && ($entry['Sgmt'] ?? null) === 'CM' && ($entry['Src'] ?? null) === 'NSE'
                && ($entry['FinInstrmTp'] ?? null) === 'STK' && ($entry['FinInstrmId'] ?? null) === '762608'
                && ($entry['ISIN'] ?? null) === 'INE00HZ01011' && ($entry['TckrSymb'] ?? null) === 'BNAGROCHEM'
                && ($entry['SctySrs'] ?? null) === 'EQ') {
                $matches[] = $entry;
            }
        }
        fclose($stream);
        if (count($matches) !== 1) $this->invalidBundle();
        $match = $matches[0];
        $row = [
            'open' => (float) ($match['OpnPric'] ?? 0), 'high' => (float) ($match['HghPric'] ?? 0),
            'low' => (float) ($match['LwPric'] ?? 0), 'close' => (float) ($match['ClsPric'] ?? 0),
            'volume' => (int) ($match['TtlTradgVol'] ?? -1),
        ];
        if ($row !== ['open' => 159.0, 'high' => 162.66, 'low' => 158.68, 'close' => 162.66, 'volume' => 101471]) {
            $this->invalidBundle();
        }

        return $row;
    }

    private function assertEligibleIssue(DataQualityIssue $issue, ?\App\Models\Stock $stock): void
    {
        if ($stock === null || (int) $issue->stock_id !== 7096 || $stock->symbol !== 'BNAGROCHEM'
            || $stock->exchange !== 'NSE' || $stock->isin !== 'INE00HZ01029'
            || $issue->issue_type !== DataQualityIssue::TYPE_CORPORATE_ACTION
            || $issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW
            || $issue->detection_method !== DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED
            || ! $issue->exchange_match || ! in_array($issue->corporate_action_type, ['split', 'face_value_split'], true)
            || $issue->ex_date?->toDateString() !== '2026-11-16'
            || abs((float) $issue->suggested_ratio - 10.0) >= 0.000001) {
            throw new DomainException('Official F042 evidence can only be staged for the exact pending BNAGROCHEM exchange-feed issue.', 'DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', 422);
        }
    }

    private function readBounded(UploadedFile $file, int $maxBytes): string
    {
        $bytes = $file->get();
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > $maxBytes) $this->invalidBundle();
        return $bytes;
    }

    private function samePayload(array $actual, array $expected): bool
    {
        ksort($actual);
        ksort($expected);
        // JSON storage may decode integral-looking decimal values as ints or floats.
        return $actual == $expected;
    }

    private function labelFor(string $key): string
    {
        return match ($key) {
            'bnagrochem_listing_circular' => 'NSE Listing circular CML77412',
            'bnagrochem_clearing_circular' => 'NSE Clearing circular CMPT77438',
            default => 'NSE Nov. 13, 2026 CM bhavcopy row',
        };
    }

    private function hashFor(string $key, array $payload): string
    {
        return (string) ($payload['sha256'] ?? $payload['source_archive_sha256'] ?? '');
    }

    private function invalidBundle(): never
    {
        throw new DomainException('Uploaded source bundle does not match the retained official F042 artifacts and exact exchange row.', 'DATA_QUALITY_SOURCE_EVIDENCE_INVALID', 422);
    }
}
