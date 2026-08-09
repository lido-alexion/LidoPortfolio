<?php

namespace Tests\Unit;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueEvidence;
use App\Services\DataQualityIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityIssueServiceTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    public function test_duplicate_pending_detection_does_not_create_second_issue(): void
    {
        $stock = $this->createDataQualityStock();
        $service = app(DataQualityIssueService::class);
        $runId = DataQualityIssueService::newDetectionRunId('test:dedupe');

        $first = $service->createOrRefreshPendingIssueForStock(
            $stock,
            DataQualityIssue::TYPE_CORPORATE_ACTION,
            DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            [
                'detection_source' => 'exchange_feed',
                'suggested_ratio' => 2.0,
                'latest_suggested_ratio' => 2.0,
                'ex_date' => '2026-02-01',
                'exchange_match' => true,
                'confidence' => 1.0,
                'detected_at' => now(),
            ],
            [[
                'evidence_key' => 'exchange_ratio',
                'evidence_value' => '1:2',
            ]],
            $runId,
        );

        $second = $service->createOrRefreshPendingIssueForStock(
            $stock,
            DataQualityIssue::TYPE_CORPORATE_ACTION,
            DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            [
                'detection_source' => 'exchange_feed',
                'suggested_ratio' => 2.0,
                'latest_suggested_ratio' => 2.5,
                'ex_date' => '2026-02-01',
                'exchange_match' => true,
                'confidence' => 1.0,
                'detected_at' => now(),
            ],
            [[
                'evidence_key' => 'exchange_ratio',
                'evidence_value' => '1:2.5',
            ]],
            $runId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DataQualityIssue::query()->count());
        $this->assertSame(2.0, (float) $second->fresh()->suggested_ratio);
        $this->assertSame(2.5, (float) $second->fresh()->latest_suggested_ratio);
    }

    public function test_repeated_detection_appends_evidence_and_preserves_original_detection_fields(): void
    {
        $stock = $this->createDataQualityStock('EVID');
        $service = app(DataQualityIssueService::class);
        $runOne = DataQualityIssueService::newDetectionRunId('test:run1');
        $runTwo = DataQualityIssueService::newDetectionRunId('test:run2');
        $originalDetectedAt = now()->subDay();

        $issue = $service->createOrRefreshPendingIssueForStock(
            $stock,
            DataQualityIssue::TYPE_CORPORATE_ACTION,
            DataQualityIssue::DETECTION_METHOD_HEURISTIC_GAP,
            [
                'detection_source' => 'heuristic',
                'suggested_ratio' => 2.0,
                'latest_suggested_ratio' => 2.0,
                'ex_date' => '2026-03-01',
                'exchange_match' => false,
                'confidence' => 0.8,
                'detected_at' => $originalDetectedAt,
            ],
            [[
                'evidence_key' => 'gap_ratio',
                'evidence_value' => '2.000000',
            ]],
            $runOne,
        );

        $service->createOrRefreshPendingIssueForStock(
            $stock,
            DataQualityIssue::TYPE_CORPORATE_ACTION,
            DataQualityIssue::DETECTION_METHOD_HEURISTIC_GAP,
            [
                'detection_source' => 'heuristic',
                'suggested_ratio' => 99.0,
                'latest_suggested_ratio' => 2.1,
                'ex_date' => '2026-03-01',
                'exchange_match' => false,
                'confidence' => 0.9,
                'detected_at' => now(),
            ],
            [[
                'evidence_key' => 'gap_ratio',
                'evidence_value' => '2.050000',
            ]],
            $runTwo,
        );

        $fresh = $issue->fresh(['evidences']);
        $this->assertSame(2, $fresh->evidences->count());
        $this->assertSame(2.0, (float) $fresh->suggested_ratio);
        $this->assertSame($originalDetectedAt->toDateTimeString(), $fresh->detected_at->toDateTimeString());
        $this->assertSame(2.1, (float) $fresh->latest_suggested_ratio);

        $runIds = $fresh->evidences
            ->pluck('evidence_payload')
            ->filter()
            ->map(fn ($payload) => $payload['detection_run_id'] ?? null)
            ->values()
            ->all();
        $this->assertContains($runOne, $runIds);
        $this->assertContains($runTwo, $runIds);
    }

    public function test_detection_run_id_is_stored_on_evidence_payload(): void
    {
        $stock = $this->createDataQualityStock('RUNID');
        $service = app(DataQualityIssueService::class);
        $runId = DataQualityIssueService::newDetectionRunId('test:run');

        $issue = $service->createIssue([
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'exchange_feed',
            'suggested_ratio' => 2.0,
            'ex_date' => '2026-04-01',
            'exchange_match' => true,
            'detected_at' => now(),
        ], [[
            'evidence_key' => 'exchange_ratio',
            'evidence_value' => '1:2',
        ]], $runId);

        $evidence = DataQualityIssueEvidence::query()->where('issue_id', $issue->id)->first();
        $this->assertNotNull($evidence);
        $this->assertSame($runId, $evidence->evidence_payload['detection_run_id'] ?? null);
    }
}
