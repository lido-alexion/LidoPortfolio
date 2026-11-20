<?php

namespace Tests\Feature\V9;

use App\Models\ForwardCollectionWork;
use App\Models\User;
use App\Services\AdminOperationalAlertService;
use App\Services\DataCompletenessService;
use App\Services\ForwardDataHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForwardDataAdminHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_price_coverage_is_actionable_without_source_proof_and_other_gaps_always_are(): void
    {
        $service = new DataCompletenessService($this->mock(\App\Services\ML\MlHistoricalUniverseMembershipService::class));
        $report = ['datasets' => [
            'daily_prices' => ['freshness' => 'incomplete'],
            'nse_membership' => ['freshness' => 'complete'],
            'fundamentals' => ['freshness' => 'complete'],
        ]];
        $this->assertFalse($service->isComplete($report));
        $this->assertTrue($service->hasActionableDatasetFailures($report));
        $this->assertFalse($service->hasActionableDatasetFailures($report, true));
        $report['datasets']['fundamentals']['freshness'] = 'incomplete';
        $this->assertTrue($service->hasActionableDatasetFailures($report, true));
    }

    public function test_unconfigured_required_sources_are_blocked_even_without_work_rows(): void
    {
        config([
            'forward_data.start_date' => null,
            'ml.historical_universe.mii_path' => '',
            'ml.historical_universe.bhavcopy_path' => '',
            'services.data_quality.corporate_actions_feed_url' => '',
        ]);

        $report = app(ForwardDataHealthService::class)->report();

        $this->assertSame('blocked', $report['datasets']['official_nse_membership']['state']);
        $this->assertContains('forward_start_date_not_configured', $report['datasets']['official_nse_membership']['reason_codes']);
        $this->assertContains('corporate_actions_feed_not_configured', $report['datasets']['corporate_actions_feed']['reason_codes']);
        $this->assertNotEmpty($report['alerts']);
    }

    public function test_publication_grace_is_visible_but_not_alerted_until_due(): void
    {
        config([
            'forward_data.start_date' => '2026-09-28',
            'ml.historical_universe.mii_path' => '/tmp/mii',
            'ml.historical_universe.bhavcopy_path' => '',
            'services.data_quality.corporate_actions_feed_url' => 'https://example.test/corporate-actions',
        ]);

        ForwardCollectionWork::query()->create([
            'dataset_key' => 'official_nse_membership',
            'exchange' => 'NSE',
            'session_date' => '2026-09-30',
            'scope_key' => 'active_eligible_nse',
            'state' => 'waiting_publication',
            'next_attempt_at' => now()->subHour(),
            'acquisition_eligible_at' => now()->subHour(),
            'publication_grace_until' => now()->addHour(),
        ]);

        $report = app(ForwardDataHealthService::class)->report();

        $this->assertSame('grace', $report['datasets']['official_nse_membership']['state']);
        $this->assertSame(1, $report['datasets']['official_nse_membership']['publication_grace']);
        $this->assertFalse(collect($report['alerts'])->contains(fn (array $alert) => ($alert['context']['dataset'] ?? null) === 'official_nse_membership'));
    }

    public function test_admin_api_is_authorized_paginated_and_retry_is_idempotent(): void
    {
        $work = ForwardCollectionWork::query()->create([
            'dataset_key' => 'official_nse_membership',
            'exchange' => 'NSE',
            'session_date' => '2026-09-30',
            'scope_key' => 'active_eligible_nse',
            'state' => 'retry_wait',
            'next_attempt_at' => now()->addHour(),
            'last_error_code' => 'provider_transport_failure',
        ]);
        $investor = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($investor);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($investor)->getJson('/api/forward-data/health')->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/forward-data/work?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.total', 1);

        $this->actingAs($admin)
            ->postJson('/api/forward-data/retry', ['ids' => [$work->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.idempotent', false);

        $this->actingAs($admin)
            ->postJson('/api/forward-data/retry', ['ids' => [$work->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.idempotent', true);
    }

    public function test_admin_work_api_returns_requested_page_metadata_and_rows(): void
    {
        foreach ([1, 2, 3] as $day) {
            ForwardCollectionWork::query()->create([
                'dataset_key' => 'official_nse_membership',
                'exchange' => 'NSE',
                'session_date' => sprintf('2026-09-%02d', $day),
                'scope_key' => "active_eligible_nse_{$day}",
                'state' => 'retry_wait',
                'next_attempt_at' => now()->addHour(),
            ]);
        }
        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)
            ->getJson('/api/forward-data/work?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 3)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.data.0.session_date', '2026-09-02');
    }

    public function test_data003_admin_endpoints_reject_missing_and_invalid_bearer_credentials(): void
    {
        foreach (['/api/forward-data/health', '/api/forward-data/work', '/api/operational-alerts'] as $path) {
            $this->get($path)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Unauthenticated.')
                ->assertHeader('X-Request-ID');

            $this->withHeader('Authorization', 'Bearer invalid-data003-regression-token')
                ->get($path)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Unauthenticated.')
                ->assertHeader('X-Request-ID');
        }
    }

    public function test_completeness_command_aggregates_failure_and_notifies_on_recovery(): void
    {
        $completeness = $this->mock(DataCompletenessService::class);
        $health = $this->mock(ForwardDataHealthService::class);
        $alerts = $this->mock(AdminOperationalAlertService::class);

        $completeness->shouldReceive('report')->once()->andReturn(['datasets' => []]);
        $completeness->shouldReceive('hasActionableDatasetFailures')->once()->with(['datasets' => [], 'forward_data' => ['alerts' => [['key' => 'forward_data_incomplete']]]], false)->andReturn(true);
        $health->shouldReceive('report')->once()->andReturn(['alerts' => [['key' => 'forward_data_incomplete']]]);
        $alerts->shouldReceive('hasValidatedDailyPriceRowsOnly')->once()->andReturn(false);
        $alerts->shouldReceive('recordUnattendedFailure')->once();
        $alerts->shouldReceive('syncAndNotify')->once();

        $this->artisan('stox:check-data-completeness')->assertExitCode(1);

        $completeness = $this->mock(DataCompletenessService::class);
        $health = $this->mock(ForwardDataHealthService::class);
        $alerts = $this->mock(AdminOperationalAlertService::class);
        $completeness->shouldReceive('report')->once()->andReturn(['datasets' => ['daily_prices' => ['freshness' => 'complete']]]);
        $completeness->shouldReceive('hasActionableDatasetFailures')->once()->with(['datasets' => ['daily_prices' => ['freshness' => 'complete']], 'forward_data' => ['alerts' => []]], false)->andReturn(false);
        $health->shouldReceive('report')->once()->andReturn(['alerts' => []]);
        $alerts->shouldReceive('hasValidatedDailyPriceRowsOnly')->once()->andReturn(false);
        $alerts->shouldReceive('clearUnattendedFailure')->once()->with(AdminOperationalAlertService::KEY_DATA_COMPLETENESS)->andReturn(true);
        $alerts->shouldReceive('syncAndNotify')->once();

        $this->artisan('stox:check-data-completeness')->assertExitCode(0);
    }

    public function test_partial_daily_price_coverage_clears_composite_alert_without_telegram(): void
    {
        $completeness = $this->mock(DataCompletenessService::class);
        $health = $this->mock(ForwardDataHealthService::class);
        $alerts = $this->mock(AdminOperationalAlertService::class);
        $report = ['as_of' => '2026-07-06T20:30:00+05:30', 'datasets' => ['daily_prices' => [
            'freshness' => 'incomplete', 'coverage' => 80.0, 'last_successful_ingestion' => '2026-07-03',
        ]]];
        $completeness->shouldReceive('report')->once()->andReturn($report);
        $expectedReport = $report;
        $expectedReport['forward_data'] = ['alerts' => []];
        $completeness->shouldReceive('hasActionableDatasetFailures')->once()->with($expectedReport, true)->andReturn(false);
        $health->shouldReceive('report')->once()->andReturn(['alerts' => []]);
        $alerts->shouldReceive('hasValidatedDailyPriceRowsOnly')->once()->andReturn(true);
        $alerts->shouldReceive('clearUnattendedFailure')->once()->with(AdminOperationalAlertService::KEY_DATA_COMPLETENESS)->andReturn(true);
        $alerts->shouldReceive('syncAndNotify')->once();
        $alerts->shouldNotReceive('recordUnattendedFailure');

        $this->artisan('stox:check-data-completeness')->assertExitCode(0);
    }

    public function test_non_universe_dataset_failure_still_records_and_notifies(): void
    {
        $completeness = $this->mock(DataCompletenessService::class);
        $health = $this->mock(ForwardDataHealthService::class);
        $alerts = $this->mock(AdminOperationalAlertService::class);
        $completeness->shouldReceive('report')->once()->andReturn(['datasets' => ['fundamentals' => ['freshness' => 'incomplete']]]);
        $completeness->shouldReceive('hasActionableDatasetFailures')->once()->with(['datasets' => ['fundamentals' => ['freshness' => 'incomplete']], 'forward_data' => ['alerts' => []]], false)->andReturn(true);
        $health->shouldReceive('report')->once()->andReturn(['alerts' => []]);
        $alerts->shouldReceive('hasValidatedDailyPriceRowsOnly')->once()->andReturn(false);
        $alerts->shouldReceive('recordUnattendedFailure')->once();
        $alerts->shouldReceive('syncAndNotify')->once();

        $this->artisan('stox:check-data-completeness')->assertExitCode(1);
    }

    public function test_daily_price_gap_without_current_source_proof_records_composite_alert(): void
    {
        $report = ['datasets' => ['daily_prices' => ['freshness' => 'incomplete', 'coverage' => 0.0]]];
        $expected = $report;
        $expected['forward_data'] = ['alerts' => []];
        $completeness = $this->mock(DataCompletenessService::class);
        $health = $this->mock(ForwardDataHealthService::class);
        $alerts = $this->mock(AdminOperationalAlertService::class);
        $completeness->shouldReceive('report')->once()->andReturn($report);
        $completeness->shouldReceive('hasActionableDatasetFailures')->once()->with($expected, false)->andReturn(true);
        $health->shouldReceive('report')->once()->andReturn(['alerts' => []]);
        $alerts->shouldReceive('hasValidatedDailyPriceRowsOnly')->once()->andReturn(false);
        $alerts->shouldReceive('recordUnattendedFailure')->once()->with(
            AdminOperationalAlertService::KEY_DATA_COMPLETENESS,
            'StoX data completeness incomplete',
            'One or more actionable ML readiness datasets has a missing session or incomplete coverage.',
            $expected,
        );
        $alerts->shouldReceive('syncAndNotify')->once();
        $alerts->shouldNotReceive('clearUnattendedFailure');

        $this->artisan('stox:check-data-completeness')->assertExitCode(1);
    }
}
