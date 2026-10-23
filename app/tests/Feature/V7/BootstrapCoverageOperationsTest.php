<?php

namespace Tests\Feature\V7;

use App\Http\Controllers\Api\V1\BootstrapCoverageOperationsController;
use App\Models\Stock;
use App\Models\User;
use App\Models\V7\BootstrapCoverageGap;
use App\Models\V7\BootstrapQualityWarning;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapCoverageOperationsService;
use App\Services\Fundamentals\BootstrapWarningCategory;
use App\Services\Fundamentals\FundamentalMetricCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BootstrapCoverageOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'auth:sanctum', 'admin'])->prefix('api/package-b-test')->group(function (): void {
            Route::get('/summary', [BootstrapCoverageOperationsController::class, 'summary']);
            Route::get('/gaps', [BootstrapCoverageOperationsController::class, 'gaps']);
            Route::get('/warnings', [BootstrapCoverageOperationsController::class, 'warnings']);
            Route::post('/reconcile', [BootstrapCoverageOperationsController::class, 'reconcile']);
            Route::post('/gaps/ack', [BootstrapCoverageOperationsController::class, 'acknowledgeGaps']);
            Route::post('/warnings/ack', [BootstrapCoverageOperationsController::class, 'acknowledgeWarnings']);
        });
    }

    public function test_admin_authorization_and_get_endpoints_do_not_reconcile_or_mutate(): void
    {
        $run = $this->runWithJob(['facts_rejected' => 1]);
        $this->getJson('/api/package-b-test/summary')->assertUnauthorized();
        $member = User::factory()->create(['is_admin' => false]);
        $this->actingAs($member)->getJson('/api/package-b-test/summary')->assertForbidden();
        $admin = User::factory()->admin()->create();
        $before = [$run->jobs()->count(), BootstrapCoverageGap::count(), BootstrapQualityWarning::count()];
        $this->actingAs($admin)->getJson('/api/package-b-test/summary')->assertOk()->assertJsonPath('data.eligible_equity_population', 1);
        $this->getJson('/api/package-b-test/gaps?per_page=2&state=actionable&metric=revenue')->assertOk()->assertJsonPath('data.per_page', 2);
        $this->getJson('/api/package-b-test/warnings?per_page=3&category=anomaly_guard_review')->assertOk()->assertJsonPath('data.per_page', 3);
        $this->assertSame($before, [$run->jobs()->count(), BootstrapCoverageGap::count(), BootstrapQualityWarning::count()]);
    }

    public function test_explicit_reconciliation_is_idempotent_per_run_and_later_run_is_new_occurrence(): void
    {
        $stock = $this->stock('WARN');
        $firstRun = $this->runWithJob(['stock_id' => $stock->id, 'facts_rejected' => 1, 'anomalies_count' => 1]);
        $service = app(BootstrapCoverageOperationsService::class);
        $first = $service->reconcile($firstRun);
        $again = $service->reconcile($firstRun);
        $this->assertGreaterThan(0, $first['created_gaps']);
        $this->assertSame(0, $again['created_gaps']);
        $gap = BootstrapCoverageGap::query()->where('stock_id', $stock->id)->where('metric_key', 'revenue')->where('cadence', 'quarterly')->firstOrFail();
        $this->assertSame(1, \DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->count());
        $this->assertSame(1, BootstrapQualityWarning::query()->where('run_id', $firstRun->id)->count());
        $admin = User::factory()->admin()->create();
        $firstWarning = BootstrapQualityWarning::query()->where('run_id', $firstRun->id)->firstOrFail();
        $this->assertSame(BootstrapWarningCategory::ANOMALY_REVIEW, $firstWarning->category);
        $presentedAnomaly = $service->warnings()->getCollection()->first();
        $this->assertSame(BootstrapWarningCategory::ANOMALY_REVIEW, $presentedAnomaly['category']);
        $this->assertSame(['anomaly_count' => 1], $presentedAnomaly['details']);
        $this->assertSame('One or more facts were rejected by the existing bootstrap anomaly guard.', $presentedAnomaly['summary']);
        $service->acknowledgeWarnings([$firstWarning->id], $admin->id, 'Reviewed first occurrence');
        $this->assertSame(0, $service->acknowledgeWarnings([$firstWarning->id], $admin->id, 'Duplicate acknowledgement'));
        $this->assertSame(1, \DB::table('stox_fund_bootstrap_warning_ack_events')->where('warning_id', $firstWarning->id)->count());
        $secondRun = $this->runWithJob(['stock_id' => $stock->id, 'facts_rejected' => 1]);
        $service->reconcile($secondRun);
        $this->assertSame(2, BootstrapQualityWarning::query()->where('stock_id', $stock->id)->count());
        $this->assertSame('acknowledged', $firstWarning->fresh()->state);
        $this->assertSame('unresolved', BootstrapQualityWarning::query()->where('run_id', $secondRun->id)->firstOrFail()->state);
    }

    public function test_accepted_missing_gap_stays_accepted_across_runs_and_current_fact_resolves_it(): void
    {
        $stock = $this->stock('GAP');
        $run = $this->runWithJob(['stock_id' => $stock->id]);
        $service = app(BootstrapCoverageOperationsService::class);
        $service->reconcile($run);
        $gap = BootstrapCoverageGap::query()->where('run_id', $run->id)->where('metric_key', 'revenue')->where('cadence', 'quarterly')->firstOrFail();
        $admin = User::factory()->admin()->create();
        $service->acknowledgeGaps([$gap->id], $admin->id, 'Expected missing in source');
        $this->assertSame(0, $service->acknowledgeGaps([$gap->id], $admin->id, 'Duplicate acknowledgement'));
        $gap->refresh();
        $this->assertSame('accepted', $gap->state);
        $this->assertSame($admin->id, $gap->acknowledged_by);
        $this->assertSame(1, \DB::table('stox_fund_bootstrap_gap_ack_events')->where('gap_id', $gap->id)->count());
        $this->assertSame(1, \DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->count());
        $secondRun = $this->runWithJob(['stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_RETRY]);
        $service->reconcile($secondRun);
        $gap->refresh();
        $this->assertSame('accepted', $gap->state);
        $this->assertSame(2, \DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->count());

        $this->fact($stock->id, 'revenue', 'quarterly');
        $thirdRun = $this->runWithJob(['stock_id' => $stock->id]);
        $result = $service->reconcile($thirdRun);
        $this->assertGreaterThanOrEqual(1, $result['resolved_gaps']);
        $gap->refresh();
        $this->assertSame('resolved', $gap->state);
        $this->assertSame($admin->id, $gap->acknowledged_by);
        $this->assertNotNull($gap->evidence_at);
    }

    public function test_bulk_gap_acknowledgement_rejects_incompatible_targets_atomically(): void
    {
        $one = $this->gap(['metric_key' => 'revenue', 'identity_hash' => hash('sha256', 'one')]);
        $two = $this->gap(['metric_key' => 'net_income', 'identity_hash' => hash('sha256', 'two')]);
        $admin = User::factory()->admin()->create();
        try {
            app(BootstrapCoverageOperationsService::class)->acknowledgeGaps([$one->id, $two->id], $admin->id, null);
            $this->fail('Expected incompatible selection to be rejected.');
        } catch (ValidationException) {
            $this->assertSame('actionable', $one->fresh()->state);
            $this->assertSame('actionable', $two->fresh()->state);
        }
    }

    public function test_accepted_source_failure_reopens_only_after_new_canonical_source_evidence(): void
    {
        $stock = $this->stock('FILLABLE');
        $service = app(BootstrapCoverageOperationsService::class);
        $initialRun = $this->runWithJob(['stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED]);
        $service->reconcile($initialRun);
        $gap = BootstrapCoverageGap::query()->where('stock_id', $stock->id)->where('metric_key', 'revenue')->where('cadence', 'quarterly')->firstOrFail();
        $this->assertSame('ingestion_not_yet_attempted', $gap->reason);
        $failedRun = $this->runWithJob(['stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_FAILED, 'last_error' => 'Provider unavailable']);
        $service->reconcile($failedRun);
        $this->assertSame('source_unavailable', $gap->fresh()->reason);
        $this->assertSame('source_unavailable', \DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->where('run_id', $failedRun->id)->value('observed_reason'));
        $admin = User::factory()->admin()->create();
        $service->acknowledgeGaps([$gap->id], $admin->id, null);

        $retryOnlyRun = $this->runWithJob(['stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_RETRY]);
        $service->reconcile($retryOnlyRun);
        $this->assertSame('accepted', $gap->fresh()->state);
        $this->assertSame('ingestion_not_yet_attempted', $gap->fresh()->reason);
        $this->assertSame('source_unavailable', $gap->fresh()->acknowledged_reason);
        $this->assertSame('ingestion_not_yet_attempted', \DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->where('run_id', $retryOnlyRun->id)->value('observed_reason'));

        \DB::table('stox_fundamental_provider_checks')->insert([
            'stock_id' => $stock->id, 'cadence' => 'quarterly', 'last_successful_check_at' => now()->addSecond(),
            'provider' => 'yahoo', 'response_hash' => hash('sha256', 'successful-source-check'),
            'requested_symbol' => $stock->symbol, 'provider_symbol' => 'FILLABLE.NS', 'source_meta' => json_encode(['check_kind' => 'merged_source_rows']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $evidenceRun = $this->runWithJob(['stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_RETRY]);
        $result = $service->reconcile($evidenceRun);
        $this->assertGreaterThan(0, $result['reopened_gaps']);
        $this->assertSame('reopened', $gap->fresh()->state);
        $this->assertSame($admin->id, $gap->fresh()->acknowledged_by);
        $event = \DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->where('run_id', $evidenceRun->id)->first();
        $this->assertSame('source_available_after_acknowledgement', $event->fillability_evidence);
        $this->assertSame(0, $service->reconcile($evidenceRun)['reopened_gaps']);
    }

    public function test_warning_bulk_acknowledgement_is_audited_and_gap_resolver_returns_stock_only_scope(): void
    {
        $stock = $this->stock('RESOLVE');
        $gap = $this->gap(['stock_id' => $stock->id, 'identity_hash' => hash('sha256', 'resolve')]);
        $warning = BootstrapQualityWarning::query()->create([
            'run_id' => $gap->run_id, 'stock_id' => $stock->id, 'category' => 'facts_rejected_by_quality_gate',
            'identity_hash' => hash('sha256', 'warning'), 'summary' => 'Normalized anomaly warning', 'state' => 'unresolved',
        ]);
        $service = app(BootstrapCoverageOperationsService::class);
        $this->assertSame([$stock->id], $service->selectedStockIdsForLaunch([$gap->id]));
        $admin = User::factory()->admin()->create();
        $this->assertSame(1, $service->acknowledgeWarnings([$warning->id], $admin->id, 'Reviewed'));
        $this->assertSame('acknowledged', $warning->fresh()->state);
        $this->assertSame($admin->id, $warning->fresh()->acknowledged_by);
        $this->assertSame('Reviewed', $warning->fresh()->acknowledgement_note);
    }

    public function test_fully_covered_fixture_does_not_create_resolved_gap_records(): void
    {
        $stock = $this->stock('COVERED');
        $catalog = app(FundamentalMetricCatalog::class);
        foreach ($catalog->primaryFactKeys() as $metric) {
            foreach (['quarterly', 'annual'] as $cadence) $this->fact($stock->id, $metric, $cadence);
        }
        $run = $this->runWithJob(['stock_id' => $stock->id]);
        $result = app(BootstrapCoverageOperationsService::class)->reconcile($run);
        $this->assertSame(0, BootstrapCoverageGap::query()->where('stock_id', $stock->id)->count());
        $this->assertSame(0, $result['created_gaps']);
        $this->assertSame(0, $result['resolved_gaps']);
    }

    public function test_reconciliation_chunks_job_history_across_page_boundary(): void
    {
        $run = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'complete', 'requested' => 51]);
        for ($index = 0; $index < 51; $index++) {
            $stock = $this->stock('CHUNK'.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
            FundamentalBootstrapJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id,
                'status' => FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA, 'attempts' => 1]);
        }
        $catalog = \Mockery::mock(FundamentalMetricCatalog::class);
        $catalog->shouldReceive('primaryFactKeys')->andReturn(['revenue']);
        $service = new BootstrapCoverageOperationsService($catalog);
        $first = $service->reconcile($run);
        $second = $service->reconcile($run);
        $this->assertSame(102, BootstrapCoverageGap::query()->where('run_id', $run->id)->count());
        $this->assertSame(102, $first['created_gaps']);
        $this->assertSame(0, $second['created_gaps']);
        $this->assertSame(102, \DB::table('stox_fund_bootstrap_gap_observations')->where('run_id', $run->id)->count());
    }

    public function test_warning_presenter_allowlists_categories_summaries_and_details(): void
    {
        $stock = $this->stock('SAFE');
        $run = $this->runWithJob(['stock_id' => $stock->id]);
        $warning = BootstrapQualityWarning::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'category' => 'unknown-secret-category',
            'identity_hash' => hash('sha256', 'unsafe-warning-fixture'), 'summary' => 'database password is do-not-show',
            'details' => ['raw_error' => 'API_TOKEN_SECRET', 'checkpoint' => '/srv/private', 'anomaly_count' => 3], 'state' => 'unresolved',
        ]);
        $presented = app(BootstrapCoverageOperationsService::class)->warnings()->getCollection()->first();
        $this->assertSame($warning->id, $presented['id']);
        $this->assertSame('unknown_quality_warning', $presented['category']);
        $this->assertSame('A normalized quality warning was recorded.', $presented['summary']);
        $this->assertSame(['anomaly_count' => 3], $presented['details']);
        $this->assertStringNotContainsString('API_TOKEN_SECRET', json_encode($presented));
        $this->assertStringNotContainsString('/srv/private', json_encode($presented));
        $this->assertStringNotContainsString('do-not-show', json_encode($presented));

        $gap = $this->gap(['metric_key' => 'raw-sensitive-metric', 'cadence' => 'private-cadence',
            'reason' => 'raw-provider-error-token', 'state' => 'private-checkpoint']);
        $safeGap = app(BootstrapCoverageOperationsService::class)->gaps()->getCollection()->first();
        $this->assertSame($gap->id, $safeGap['id']);
        $this->assertNull($safeGap['metric']);
        $this->assertNull($safeGap['cadence']);
        $this->assertSame('evidence_unknown', $safeGap['reason']);
        $this->assertSame('actionable', $safeGap['state']);
        $this->assertStringNotContainsString('raw-provider-error-token', json_encode($safeGap));
        $this->assertStringNotContainsString('private-checkpoint', json_encode($safeGap));
    }

    private function stock(string $symbol): Stock
    {
        return Stock::query()->create(['symbol' => $symbol, 'exchange' => 'NSE', 'name' => $symbol, 'is_active' => true, 'admin_deactivated' => false, 'is_benchmark' => false]);
    }

    private function runWithJob(array $overrides = []): FundamentalBootstrapRun
    {
        $stock = isset($overrides['stock_id']) ? Stock::query()->findOrFail($overrides['stock_id']) : $this->stock('RUN'.random_int(10000, 99999));
        $run = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'complete', 'requested' => 1, 'completed' => 1]);
        FundamentalBootstrapJob::query()->create(array_merge([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_COMPLETE_GOOD,
            'attempts' => 1, 'facts_rejected' => 0, 'anomalies_count' => 0,
        ], $overrides));
        return $run;
    }

    private function fact(int $stockId, string $metric, string $cadence): void
    {
        \DB::table('stox_fundamental_facts')->insert([
            'stock_id' => $stockId, 'provider' => 'yahoo', 'statement_type' => 'income_statement', 'cadence' => $cadence,
            'statement_basis' => 'consolidated', 'fact_key' => $metric, 'period_end' => '2026-06-30', 'value' => 10,
            'availability_date' => '2026-07-20', 'first_fetched_at' => now(), 'revision_hash' => hash('sha256', $metric.$cadence),
            'revision_number' => 1, 'is_current' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function gap(array $overrides = []): BootstrapCoverageGap
    {
        $stock = isset($overrides['stock_id']) ? Stock::query()->findOrFail($overrides['stock_id']) : $this->stock('ACK'.random_int(10000, 99999));
        $run = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'complete', 'requested' => 1]);
        return BootstrapCoverageGap::query()->create(array_merge([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'metric_key' => 'revenue', 'cadence' => 'quarterly',
            'reason' => 'evidence_unknown', 'state' => 'actionable',
            'identity_hash' => hash('sha256', 'gap'.random_int(1, PHP_INT_MAX)),
        ], $overrides));
    }
}
