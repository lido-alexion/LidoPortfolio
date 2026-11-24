<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueEvidence;
use App\Models\Stock;
use App\Services\BnagrochemF042EvidenceValidator;
use Tests\TestCase;

class BnagrochemF042EvidenceValidatorTest extends TestCase
{
    public function test_unrelated_future_action_for_same_stock_is_outside_the_blocker(): void
    {
        $issue = new DataQualityIssue([
            'stock_id' => 7096, 'symbol' => 'BNAGROCHEM',
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'corporate_action_type' => 'split', 'ex_date' => '2027-01-04',
        ]);

        $validator = app(BnagrochemF042EvidenceValidator::class);
        $this->assertFalse($validator->appliesTo($issue));
        $validator->assertReviewedEvidence($issue, 2.0, true);
    }

    public function test_circulars_do_not_authorize_f042_without_configured_independent_bhavcopy_proof(): void
    {
        $issue = new DataQualityIssue([
            'stock_id' => 7096, 'symbol' => 'BNAGROCHEM', 'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'exchange_match' => true, 'suggested_ratio' => 10,
            'corporate_action_type' => 'face_value_split', 'ex_date' => '2026-11-16',
        ]);
        $issue->setRelation('stock', new Stock(['symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'isin' => 'INE00HZ01029']));
        $issue->setRelation('evidences', collect([
            $this->evidence('bnagrochem_listing_circular', [
                'document' => 'CML77412', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CML77412.pdf',
                'sha256' => 'c0faa54145565b1650c98748562c81b5444dc63d53b57c39f5e28e07aa8b4532',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'new_isin' => 'INE00HZ01029', 'ex_date' => '2026-11-16',
            ]),
            $this->evidence('bnagrochem_clearing_circular', [
                'document' => 'CMPT77438', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CMPT77438.pdf',
                'sha256' => '70d93d11da034e34af5184e63af651b04d826f7935ca375bc0c14bc116e6c454',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'ex_date' => '2026-11-16',
            ]),
            $this->evidence('bnagrochem_bhavcopy', [
                'source_url' => 'https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261113_F_0000.csv.zip',
                'source_archive_sha256' => '758ec3b60fd492a29ff3ebc6c069269bc326dc5ca662cfba8892f16939841b7b',
                'source_csv_sha256' => '5736864df9797ba7b0427ec91dc506b5cd07c283edaefa8cf3f5609da4720b9e',
                'trade_date' => '2026-11-13', 'symbol' => 'BNAGROCHEM', 'isin' => 'INE00HZ01011',
                'instrument_id' => 762608, 'open' => 159.00, 'high' => 162.66, 'low' => 158.68,
                'close' => 162.65, 'volume' => 101471,
            ]),
        ]));

        $this->expectException(DomainException::class);
        app(BnagrochemF042EvidenceValidator::class)->assertReviewedEvidence($issue, 10.0, false);
    }

    public function test_exact_reviewed_zip_csv_and_unique_stock_row_authorize_only_manual_ratio_ten(): void
    {
        $issue = new DataQualityIssue([
            'stock_id' => 7096, 'symbol' => 'BNAGROCHEM', 'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'exchange_match' => true, 'suggested_ratio' => 10,
            'corporate_action_type' => 'face_value_split', 'ex_date' => '2026-11-16',
        ]);
        $issue->setRelation('stock', new Stock(['symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'isin' => 'INE00HZ01029']));
        $issue->setRelation('evidences', collect([
            $this->evidence('bnagrochem_listing_circular', [
                'document' => 'CML77412', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CML77412.pdf',
                'sha256' => 'c0faa54145565b1650c98748562c81b5444dc63d53b57c39f5e28e07aa8b4532',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'new_isin' => 'INE00HZ01029', 'ex_date' => '2026-11-16',
            ]),
            $this->evidence('bnagrochem_clearing_circular', [
                'document' => 'CMPT77438', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CMPT77438.pdf',
                'sha256' => '70d93d11da034e34af5184e63af651b04d826f7935ca375bc0c14bc116e6c454',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'ex_date' => '2026-11-16',
            ]),
            $this->evidence('bnagrochem_bhavcopy', [
                'source_url' => 'https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261113_F_0000.csv.zip',
                'source_archive_sha256' => '758ec3b60fd492a29ff3ebc6c069269bc326dc5ca662cfba8892f16939841b7b',
                'source_csv_sha256' => '5736864df9797ba7b0427ec91dc506b5cd07c283edaefa8cf3f5609da4720b9e',
                'trade_date' => '2026-11-13', 'symbol' => 'BNAGROCHEM', 'isin' => 'INE00HZ01011',
                'instrument_id' => 762608, 'open' => 159.00, 'high' => 162.66, 'low' => 158.68,
                'close' => 162.66, 'volume' => 101471,
            ]),
        ]));

        $validator = app(BnagrochemF042EvidenceValidator::class);
        $validator->assertReviewedEvidence($issue, 10.0, false);

        $validBhavcopyPayload = $issue->evidences->firstWhere('evidence_key', 'bnagrochem_bhavcopy')->evidence_payload;
        foreach (['source_archive_sha256', 'source_csv_sha256'] as $digestField) {
            $evidence = $issue->evidences->firstWhere('evidence_key', 'bnagrochem_bhavcopy');
            $payload = $validBhavcopyPayload;
            $payload[$digestField] = str_repeat('f', 64);
            $evidence->evidence_payload = $payload;
            $issue->setRelation('evidences', collect([$evidence, ...$issue->evidences->filter(
                fn (DataQualityIssueEvidence $row): bool => $row->evidence_key !== 'bnagrochem_bhavcopy',
            )->all()]));

            try {
                $validator->assertReviewedEvidence($issue, 10.0, false);
                $this->fail("A mismatched {$digestField} must not authorize F042.");
            } catch (DomainException $exception) {
                $this->assertSame('DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', $exception->errorCode());
            }
        }

        $this->expectException(DomainException::class);
        $validator->assertReviewedEvidence($issue, 10.0, true);
    }

    private function evidence(string $key, array $payload): DataQualityIssueEvidence
    {
        $evidence = new DataQualityIssueEvidence();
        $evidence->evidence_key = $key;
        $evidence->evidence_payload = $payload;

        return $evidence;
    }
}
