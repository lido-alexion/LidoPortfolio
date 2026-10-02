<?php

namespace Tests\Unit;

use App\Services\DataCompletenessService;
use App\Services\ML\IntradayHistoricalPlatformService;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Services\Microstructure\MicrostructureCollectorControlService;
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

        $intraday = \Mockery::mock(IntradayHistoricalPlatformService::class);
        $intraday->shouldReceive('status')->once()->andReturn([
            'enabled' => false,
            'checkpoint_counts' => [],
        ]);

        $microstructure = \Mockery::mock(MicrostructureCollectorControlService::class);
        $microstructure->shouldReceive('operationalStatus')->once()->andReturn([
            'enabled' => false,
            'coverage_summary' => [],
        ]);

        $report = (new DataCompletenessService($memberships, $intraday, $microstructure))
            ->report(Carbon::parse('2026-10-02'));

        $this->assertSame('unknown', $report['datasets']['corporate_actions']['freshness']);
        $this->assertArrayHasKey('live_microstructure_feat_063', $report['datasets']);
        $this->assertArrayHasKey('minute_corpus_feat_065', $report['datasets']);
    }
}
