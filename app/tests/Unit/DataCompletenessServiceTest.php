<?php

namespace Tests\Unit;

use App\Services\DataCompletenessService;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataCompletenessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_reads_the_canonical_corporate_action_table(): void
    {
        $memberships = \Mockery::mock(MlHistoricalUniverseMembershipService::class);
        $memberships->shouldReceive('coverageForDates')->once()->andReturn([
            'missing_dates' => [],
            'coverage_percentage' => 100.0,
        ]);

        $report = (new DataCompletenessService($memberships))
            ->report(Carbon::parse('2026-10-02'));

        $this->assertSame('unknown', $report['datasets']['corporate_actions']['freshness']);
        $this->assertArrayNotHasKey('live_microstructure_feat_063', $report['datasets']);
        $this->assertArrayNotHasKey('minute_corpus_feat_065', $report['datasets']);
    }
}
