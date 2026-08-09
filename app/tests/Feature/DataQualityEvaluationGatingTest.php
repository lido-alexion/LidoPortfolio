<?php

namespace Tests\Feature;

use App\Engines\Evaluation\EvaluationEngine;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

/**
 * F042-AC009: pending_review stocks score 0 with reason data_quality_pending_review.
 */
class DataQualityEvaluationGatingTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    public function test_evaluation_engine_scores_pending_review_stock_as_zero_with_data_quality_reason(): void
    {
        $admin = $this->createAdminUser();
        $profile = $this->defaultPortfolioFor($admin);
        $stock = $this->createDataQualityStock('EVALDQ');
        $this->createPendingExchangeIssue($stock);

        $discoveryRun = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $candidate = Candidate::query()->create([
            'discovery_run_id' => $discoveryRun->id,
            'security_id' => $stock->id,
            'source' => 'test',
            'evidence' => [],
            'created_at' => now(),
        ]);

        $result = app(EvaluationEngine::class)->run($profile, $discoveryRun);

        $this->assertSame('completed', $result['run']->status);
        $this->assertCount(1, $result['results']);

        /** @var EvaluationResult $evaluationResult */
        $evaluationResult = $result['results'][0];
        $this->assertSame($candidate->id, (int) $evaluationResult->candidate_id);
        $this->assertSame(0.0, (float) $evaluationResult->score);
        $this->assertSame(0.0, (float) $evaluationResult->confidence);
        $this->assertContains('data_quality_pending_review', $evaluationResult->failed_rules ?? []);
        $this->assertSame('data_quality_pending_review', $evaluationResult->evidence['reason'] ?? null);
        $this->assertTrue((bool) ($evaluationResult->evidence['skipped'] ?? false));
    }

    public function test_evaluation_engine_does_not_skip_accepted_stock_for_data_quality_reason(): void
    {
        $admin = $this->createAdminUser();
        $profile = $this->defaultPortfolioFor($admin);
        $stock = $this->createDataQualityStock('EVALOK');
        $issue = $this->createPendingExchangeIssue($stock);
        $issue->update([
            'issue_status' => \App\Models\DataQualityIssue::STATUS_ACCEPTED,
            'resolved_at' => now(),
        ]);

        $discoveryRun = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        Candidate::query()->create([
            'discovery_run_id' => $discoveryRun->id,
            'security_id' => $stock->id,
            'source' => 'test',
            'evidence' => [],
            'created_at' => now(),
        ]);

        $result = app(EvaluationEngine::class)->run($profile, $discoveryRun);
        $evaluationResult = $result['results'][0];

        $this->assertNotSame('data_quality_pending_review', $evaluationResult->evidence['reason'] ?? null);
        $this->assertNotContains('data_quality_pending_review', $evaluationResult->failed_rules ?? []);
    }
}
