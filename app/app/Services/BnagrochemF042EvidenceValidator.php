<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;

/** Hard gate for the reviewed BNAGROCHEM listing transition in issue #383. */
class BnagrochemF042EvidenceValidator
{
    private const LISTING_DOCUMENT = 'CML77412';
    private const CLEARING_DOCUMENT = 'CMPT77438';
    private const BHAVCOPY_CSV_SHA256 = '5736864df9797ba7b0427ec91dc506b5cd07c283edaefa8cf3f5609da4720b9e';

    public function appliesTo(DataQualityIssue $issue): bool
    {
        return (int) $issue->stock_id === 7096
            && $issue->issue_type === DataQualityIssue::TYPE_CORPORATE_ACTION
            && in_array($issue->corporate_action_type, ['split', 'face_value_split'], true)
            && $issue->ex_date?->toDateString() === '2026-11-16';
    }

    public function assertReviewedEvidence(DataQualityIssue $issue, float $ratio, bool $auto): void
    {
        if (! $this->appliesTo($issue)) return;

        $stock = $issue->stock;
        $validIssue = $stock !== null
            && $stock->symbol === 'BNAGROCHEM'
            && $stock->exchange === 'NSE'
            && $stock->isin === 'INE00HZ01029'
            && $issue->issue_type === DataQualityIssue::TYPE_CORPORATE_ACTION
            && $issue->detection_method === DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED
            && $issue->exchange_match === true
            && in_array($issue->corporate_action_type, ['split', 'face_value_split'], true)
            && $issue->ex_date?->toDateString() === '2026-11-16'
            && abs((float) $issue->suggested_ratio - 10.0) < 0.000001
            && abs($ratio - 10.0) < 0.000001
            && ! $auto;

        $evidenceRows = $issue->evidences;
        $expectedKeys = ['bnagrochem_listing_circular', 'bnagrochem_clearing_circular', 'bnagrochem_bhavcopy'];
        // Keep generic exchange-feed discovery evidence intact. It is not one of
        // the three official source proofs, and repeated feed observations may
        // append multiple exchange_ratio rows for the same pending issue.
        $officialRows = $evidenceRows->whereIn('evidence_key', $expectedKeys);
        $allowedKeys = [...$expectedKeys, 'exchange_ratio'];
        $validEvidenceKeys = $officialRows->count() === count($expectedKeys)
            && $officialRows->pluck('evidence_key')->unique()->sort()->values()->all() === collect($expectedKeys)->sort()->values()->all()
            && $evidenceRows->every(fn ($row): bool => in_array($row->evidence_key, $allowedKeys, true));
        $evidence = $officialRows->keyBy('evidence_key');
        $listing = $evidence->get('bnagrochem_listing_circular')?->evidence_payload ?? [];
        $clearing = $evidence->get('bnagrochem_clearing_circular')?->evidence_payload ?? [];
        $bhavcopy = $evidence->get('bnagrochem_bhavcopy')?->evidence_payload ?? [];

        $source = config('universe_nse_price_identities.transitions.CML77412');
        $listingSource = collect($source['evidence'] ?? [])->firstWhere('document', self::LISTING_DOCUMENT);
        $clearingSource = collect($source['evidence'] ?? [])->firstWhere('document', self::CLEARING_DOCUMENT);
        $identityEvidence = collect(config('universe_nse_price_identities.identities', []))
            ->first(fn (array $identity): bool => ($identity['symbol'] ?? null) === 'BNAGROCHEM'
                && ($identity['historical_isin'] ?? null) === 'INE00HZ01011'
                && ($identity['canonical_isin'] ?? null) === 'INE00HZ01029'
                && ($identity['valid_from'] ?? null) === '2026-11-13'
                && ($identity['valid_until'] ?? null) === '2026-11-15');
        $bhavcopySource = collect($identityEvidence['evidence'] ?? [])->firstWhere('role', 'historical_observation');
        $validEvidence = is_array($listingSource) && is_array($clearingSource)
            && ($listing['document'] ?? null) === self::LISTING_DOCUMENT
            && ($listing['url'] ?? null) === ($listingSource['url'] ?? null)
            && ($listing['sha256'] ?? null) === ($listingSource['sha256'] ?? null)
            && ($listing['symbol'] ?? null) === 'BNAGROCHEM'
            && ($listing['old_isin'] ?? null) === 'INE00HZ01011'
            && ($listing['new_isin'] ?? null) === 'INE00HZ01029'
            && ($listing['ex_date'] ?? null) === '2026-11-16'
            && ($clearing['document'] ?? null) === self::CLEARING_DOCUMENT
            && ($clearing['url'] ?? null) === ($clearingSource['url'] ?? null)
            && ($clearing['sha256'] ?? null) === ($clearingSource['sha256'] ?? null)
            && ($clearing['symbol'] ?? null) === 'BNAGROCHEM'
            && ($clearing['old_isin'] ?? null) === 'INE00HZ01011'
            && ($clearing['ex_date'] ?? null) === '2026-11-16'
            // The configured digest is the downloaded ZIP SHA. The raw CSV
            // receipt has a separate digest, and the reviewed row must match
            // the unique exchange stock instrument exactly. Clearing evidence
            // corroborates identity only; it does not establish price ratio.
            && ($bhavcopy['source_archive_sha256'] ?? null) === ($bhavcopySource['sha256'] ?? null)
            && is_array($bhavcopySource)
            && ($bhavcopy['source_url'] ?? null) === ($bhavcopySource['url'] ?? null)
            && ($bhavcopy['source_csv_sha256'] ?? null) === self::BHAVCOPY_CSV_SHA256
            && ($bhavcopy['symbol'] ?? null) === 'BNAGROCHEM'
            && ($bhavcopy['isin'] ?? null) === 'INE00HZ01011'
            && ($bhavcopy['trade_date'] ?? null) === '2026-11-13'
            && (int) ($bhavcopy['instrument_id'] ?? 0) === 762608
            && (float) ($bhavcopy['open'] ?? 0) === 159.00
            && (float) ($bhavcopy['high'] ?? 0) === 162.66
            && (float) ($bhavcopy['low'] ?? 0) === 158.68
            && (float) ($bhavcopy['close'] ?? 0) === 162.66
            && (int) ($bhavcopy['volume'] ?? 0) === 101471;

        if (! $validIssue || ! $validEvidenceKeys || ! $validEvidence) {
            throw new DomainException(
                'BNAGROCHEM F042 acceptance requires human review of configured official source evidence.',
                'DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED',
                422,
            );
        }
    }
}
