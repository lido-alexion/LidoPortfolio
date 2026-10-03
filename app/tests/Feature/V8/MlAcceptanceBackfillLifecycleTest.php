<?php

namespace Tests\Feature\V8;

use App\Jobs\MlAcceptanceJob;
use App\Models\Stock;
use App\Models\User;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlUniverseMembership;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Models\V8\MlUniverseSnapshotBoundary;
use App\Services\ML\MlAcceptanceBackfillService;
use App\Services\ML\MlAcceptanceRuntime;
use App\Services\ML\MlAcceptanceSourceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MlAcceptanceBackfillLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $sourceIds = [];

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        Bus::fake();
        $this->actor = User::factory()->admin()->create();
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceIds as $id) {
            File::deleteDirectory(storage_path('app/private/ml-acceptance/sources/'.$id));
        }
        parent::tearDown();
    }

    private function source(string $date): MlAcceptanceSource
    {
        $day = Carbon::parse($date);
        $csv = "SYMBOL,SERIES,ISIN,TIMESTAMP\nTEST,EQ,INE123,".strtoupper($day->format('d-M-Y'))."\n";
        $service = app(MlAcceptanceSourceService::class);
        $source = $service->create(['version' => 1, 'source' => 'nse_cash_bhavcopy', 'date' => $date,
            'filename' => 'cm'.strtoupper($day->format('dMY')).'bhav.csv',
            'bytes' => strlen($csv), 'sha256' => hash('sha256', $csv)], $this->actor->id);
        $this->sourceIds[] = $source->id;
        $service->chunk($source, 0, $csv, $this->actor->id);
        $service->finalize($source, $this->actor->id);
        $service->validateQueued($source->id);
        $this->assertSame('sealed', $source->refresh()->status);

        return $source;
    }

    private function nextJob(MlUniverseSnapshotBackfillRun $run): MlAcceptanceJob
    {
        $job = Bus::dispatched(MlAcceptanceJob::class, fn ($job) => $job->operation === 'backfill'
            && $job->identity === (string) $run->id)->last();
        $this->assertNotNull($job, 'The next governed date must be dispatched.');
        $this->assertSame('ml-acceptance', $job->connection);
        $this->assertSame('ml-acceptance', $job->queue);

        return $job;
    }

    private function preview(): MlUniverseSnapshotBackfillRun
    {
        $sources = [$this->source('2022-11-04')->id, $this->source('2022-11-11')->id];
        $run = app(MlAcceptanceBackfillService::class)->preview($sources, $this->actor->id);
        $this->nextJob($run)->handle();
        $this->nextJob($run)->handle();
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(2, $run->acceptance['cursor']);
        $this->assertSame(0, MlUniverseSnapshotBoundary::count());

        return $run;
    }

    public function test_each_apply_job_advances_the_shared_run_until_every_date_is_committed(): void
    {
        $run = $this->preview();
        $preview = $run->acceptance['results'];
        Bus::fake();
        app(MlAcceptanceBackfillService::class)->action($run, 'apply', $this->actor->id);
        $this->nextJob($run)->handle();

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame(1, $run->acceptance['cursor']);
        $this->assertSame(['2022-11-04'], $run->processed_dates);
        $this->assertNull($run->completed_at);
        $this->assertEquals($preview, $run->acceptance['results']);
        $this->assertSame(1, MlUniverseSnapshotBoundary::count());
        Bus::assertDispatchedTimes(MlAcceptanceJob::class, 2);

        $this->nextJob($run)->handle();
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->acceptance['cursor']);
        $this->assertSame($run->requested_dates, $run->processed_dates);
        $this->assertNotNull($run->completed_at);
        $this->assertEquals($preview, $run->acceptance['results']);
        $this->assertSame([], $run->failed_dates);
        $this->assertSame([], $run->retry_counts);
        $this->assertSame(2, MlUniverseSnapshotBoundary::count());
        $this->assertSame(2, MlUniverseMembership::count());
        Bus::assertDispatchedTimes(MlAcceptanceJob::class, 2);
        foreach (MlUniverseSnapshotBoundary::all() as $boundary) {
            $date = $boundary->effective_from->toDateString();
            $this->assertSame($preview[$date]['diagnostics']['membership_sha256'], $boundary->quality_diagnostics['membership_sha256']);
        }
        $boundaries = MlUniverseSnapshotBoundary::orderBy('id')->get()->toArray();
        $members = MlUniverseMembership::orderBy('id')->get()->toArray();
        $this->nextJob($run)->handle(); // A redelivered final job cannot materialize twice.
        $this->assertEquals($boundaries, MlUniverseSnapshotBoundary::orderBy('id')->get()->toArray());
        $this->assertEquals($members, MlUniverseMembership::orderBy('id')->get()->toArray());
        Bus::assertDispatchedTimes(MlAcceptanceJob::class, 2);
    }

    public function test_fresh_preview_and_apply_preserve_a_legacy_partial_run_and_its_valid_boundary(): void
    {
        $legacy = $this->preview();
        $service = app(MlAcceptanceBackfillService::class);
        $service->action($legacy, 'apply', $this->actor->id);
        $this->nextJob($legacy)->handle();
        // Reproduce the durable production defect, only in this isolated fixture.
        $legacy->refresh()->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $legacyAttributes = $legacy->getAttributes();
        $boundary = MlUniverseSnapshotBoundary::first()->getAttributes();
        $membership = MlUniverseMembership::first()->getAttributes();

        $fresh = $service->preview($legacy->acceptance['sources'], $this->actor->id);
        $this->nextJob($fresh)->handle();
        $this->nextJob($fresh)->handle();
        $fresh->refresh();
        $this->assertTrue($fresh->acceptance['results']['2022-11-04']['existing']);
        foreach ($legacy->requested_dates as $date) {
            $this->assertSame($legacy->acceptance['results'][$date]['snapshot_sha256'], $fresh->acceptance['results'][$date]['snapshot_sha256']);
        }
        $service->action($fresh, 'apply', $this->actor->id);
        $this->nextJob($fresh)->handle();
        $this->assertSame('queued', $fresh->refresh()->status);
        $this->assertNull($fresh->completed_at);
        $this->assertSame(1, MlUniverseSnapshotBoundary::count());
        $this->nextJob($fresh)->handle();
        $this->assertSame('completed', $fresh->refresh()->status);
        $this->assertSame($fresh->requested_dates, $fresh->processed_dates);
        $this->assertSame(2, MlUniverseSnapshotBoundary::count());
        $this->assertSame(2, MlUniverseMembership::count());
        $this->assertSame($legacyAttributes, $legacy->refresh()->getAttributes());
        $this->assertSame($boundary, MlUniverseSnapshotBoundary::findOrFail($boundary['id'])->getAttributes());
        $this->assertSame($membership, MlUniverseMembership::findOrFail($membership['id'])->getAttributes());
    }

    #[DataProvider('incompleteEvidence')]
    public function test_apply_rejects_incomplete_preview_evidence_without_changing_it(string $case): void
    {
        $run = $this->preview();
        $state = $run->acceptance;
        $processed = $run->processed_dates;
        match ($case) {
            'cursor' => $state['cursor'] = 1,
            'processed_dates' => $processed = ['2022-11-04'],
            'source_count' => array_pop($state['sources']),
            'result' => array_pop($state['results']),
            'source_id' => $state['results']['2022-11-04']['diagnostics']['source_id'] = (string) Str::uuid(),
            'digest' => $state['results']['2022-11-04']['snapshot_sha256'] = '',
            'null_sources' => $state['sources'] = [null, null],
        };
        $run->forceFill(['acceptance' => $state, 'processed_dates' => $processed])->save();
        $before = $run->refresh()->getAttributes();
        Bus::fake();
        try {
            app(MlAcceptanceBackfillService::class)->action($run, 'apply', $this->actor->id);
            $this->fail('Incomplete preview was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Successful preview is required', $e->getMessage());
        }
        $this->assertSame($before, $run->refresh()->getAttributes());
        Bus::assertNothingDispatched();
    }

    public static function incompleteEvidence(): array
    {
        $cases = ['cursor', 'processed_dates', 'source_count', 'result', 'source_id', 'digest', 'null_sources'];

        return array_combine($cases, array_map(fn ($case) => [$case], $cases));
    }

    public function test_cli_fails_when_apply_reports_completed_with_an_incomplete_cursor(): void
    {
        $run = $this->preview();
        $campaign = MlAcceptanceCampaign::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => $this->actor->id, 'cutoff_date' => '2026-10-01',
            'status' => 'blocked', 'identity' => app(MlAcceptanceRuntime::class)->identity(),
            'active_models' => [], 'history' => [], 'horizons' => ['1m' => ['reference_dates' => $run->requested_dates]],
        ]);
        $this->partialMock(MlAcceptanceBackfillService::class, function ($mock) {
            $mock->shouldReceive('action')->once()->andReturnUsing(function ($run) {
                $state = $run->acceptance;
                $state['mode'] = 'apply';
                $state['cursor'] = 1;
                $run->forceFill(['acceptance' => $state, 'processed_dates' => ['2022-11-04'], 'status' => 'completed'])->save();

                return $run;
            });
        });
        $this->artisan('ml:acceptance-backfill-nse-sources', ['--campaign-id' => $campaign->id,
            '--actor-id' => $this->actor->id, '--apply-run-id' => $run->id])
            ->expectsOutputToContain('Backfill marked completed without complete requested-date evidence.')
            ->assertExitCode(1);
    }
}
