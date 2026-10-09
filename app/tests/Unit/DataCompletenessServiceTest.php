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

    public function test_coverage_and_last_ingestion_use_only_the_eligible_nse_universe(): void
    {
        $memberships = \Mockery::mock(MlHistoricalUniverseMembershipService::class);
        $memberships->shouldReceive('coverageForDates')->once()->andReturn([
            'missing_dates' => [],
            'coverage_percentage' => 100.0,
        ]);

        $asOf = Carbon::parse('2026-10-08 18:00:00', 'Asia/Kolkata');
        $expectedSession = '2026-10-07';
        $active = \App\Models\Stock::query()->create(['symbol' => 'ACTIVE', 'exchange' => 'NSE', 'name' => 'Active']);
        $inactive = \App\Models\Stock::query()->create(['symbol' => 'INACTIVE', 'exchange' => 'NSE', 'name' => 'Inactive', 'is_active' => false]);
        $bse = \App\Models\Stock::query()->create(['symbol' => 'BSEONLY', 'exchange' => 'BSE', 'name' => 'BSE only']);

        \App\Models\StockPrice::query()->create([
            'stock_id' => $active->id,
            'price_date' => '2026-10-06',
            'close_price' => 100,
            'data_source' => 'test',
        ]);
        \App\Models\StockPrice::query()->create([
            'stock_id' => $inactive->id,
            'price_date' => $expectedSession,
            'close_price' => 100,
            'data_source' => 'test',
        ]);

        foreach ([[$active, 'quarterly', '2026-10-07 12:00:00'], [$inactive, 'quarterly', '2026-10-08 12:00:00'], [$bse, 'annual', '2026-10-08 13:00:00']] as [$stock, $cadence, $checkedAt]) {
            \Illuminate\Support\Facades\DB::table('stox_fundamental_provider_checks')->insert([
                'stock_id' => $stock->id,
                'cadence' => $cadence,
                'last_successful_check_at' => $checkedAt,
                'provider' => 'test',
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);
        }

        $report = (new DataCompletenessService($memberships))->report($asOf);

        $this->assertSame('incomplete', $report['datasets']['daily_prices']['freshness']);
        $this->assertSame(0.0, $report['datasets']['daily_prices']['coverage']);
        $this->assertSame(1, $report['datasets']['daily_prices']['backlog']);
        $this->assertSame('2026-10-06', $report['datasets']['daily_prices']['last_successful_ingestion']);
        $this->assertSame('complete', $report['datasets']['fundamentals']['freshness']);
        $this->assertSame(100.0, $report['datasets']['fundamentals']['coverage']);
        $this->assertSame(0, $report['datasets']['fundamentals']['backlog']);
        $this->assertSame('2026-10-07 12:00:00', $report['datasets']['fundamentals']['last_successful_ingestion']);
    }
}
