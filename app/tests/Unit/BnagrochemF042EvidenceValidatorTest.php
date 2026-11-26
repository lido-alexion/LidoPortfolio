<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;
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

    public function test_generic_exchange_ratio_alone_cannot_authorize_the_reviewed_f042_issue(): void
    {
        $issue = new DataQualityIssue([
            'stock_id' => 7096, 'symbol' => 'BNAGROCHEM',
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'exchange_match' => true, 'suggested_ratio' => 10,
            'corporate_action_type' => 'face_value_split', 'ex_date' => '2026-11-16',
        ]);
        $issue->setRelation('stock', new Stock([
            'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'isin' => 'INE00HZ01029',
        ]));
        $ratio = new \App\Models\DataQualityIssueEvidence();
        $ratio->evidence_key = 'exchange_ratio';
        $ratio->evidence_value = '1:10';
        $ratio->evidence_payload = ['source' => 'generic exchange-feed discovery'];
        $issue->setRelation('evidences', collect([$ratio]));

        try {
            app(BnagrochemF042EvidenceValidator::class)->assertReviewedEvidence($issue, 10.0, false);
            $this->fail('The generic ratio is not a substitute for the verified official bundle.');
        } catch (DomainException $exception) {
            $this->assertSame('DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', $exception->errorCode());
        }
    }
}
