<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportArtifact;
use App\Models\ExportArtifact;
use Carbon\Carbon;
use App\Models\NotificationSource;
use App\Models\ExportNotificationOutbox;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\Export\ExportFileWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class V9Data001ExportWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_resolves_fresh_account_scoped_rows_and_publishes_completion(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);
        $artifact = $this->queuedArtifact($owner);

        // The row is created after the artifact was queued. The worker must
        // query the provider again instead of using a queued data snapshot.
        PortfolioSnapshot::query()->create([
            'profile_id' => $ownerProfile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
        PortfolioSnapshot::query()->create([
            'profile_id' => $otherProfile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '99999.9900',
            'invested_value' => '90000.0000',
        ]);

        $job = new GenerateExportArtifact($artifact->id, [
            'dataset' => 'portfolio-snapshots',
            'profile_id' => $ownerProfile->id,
            'scope' => 'full',
            'selected' => [],
            'filters' => [],
            'columns' => ['snapshot_date', 'portfolio_value', 'invested_value'],
            'metadata' => ['scope' => 'full'],
        ]);
        $frozenNow = Carbon::parse('2026-10-08 12:00:00');
        Carbon::setTestNow($frozenNow);
        try {
            $job->handle(app(ExportFileWriter::class), app(ExportDatasetRegistry::class));

            $this->assertSame('ready', $artifact->fresh()->status);
            $this->assertSame($frozenNow->copy()->addDay()->toDateTimeString(), $artifact->fresh()->expires_at->toDateTimeString());
        Storage::disk('local')->assertExists($artifact->path);
        Storage::disk('local')->assertMissing($artifact->path.'.partial');
        $csv = Storage::disk('local')->get($artifact->path);
        $this->assertStringContainsString('12345.6700', $csv);
        $this->assertStringNotContainsString('99999.9900', $csv);
        $this->assertDatabaseHas('portfolio_notification_sources', ['notification_type' => 'export.completed']);
        $source = NotificationSource::query()->where('notification_type', 'export.completed')->firstOrFail();
        $this->assertDatabaseHas('portfolio_recipient_notifications', ['source_id' => $source->id, 'user_id' => $owner->id]);
            $this->assertNotNull(ExportNotificationOutbox::query()->where('artifact_id', $artifact->id)->where('event_type', 'completed')->sole()->delivered_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_worker_resolves_basket_items_and_writes_one_xlsx_sheet_per_dataset(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        Storage::fake('local');
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
        $artifact = $this->queuedArtifact($owner);
        $artifact->update(['dataset' => 'basket', 'format' => 'xlsx', 'path' => str_replace('.csv', '.xlsx', $artifact->path)]);
        $job = new GenerateExportArtifact($artifact->id, [
            'profile_id' => $profile->id,
            'basket_items' => [[
                'dataset' => 'portfolio-snapshots',
                'scope' => 'full',
                'filters' => [],
                'selected' => [],
                'fields' => ['snapshot_date', 'portfolio_value'],
                'sheet_name' => 'Recent snapshots',
            ]],
            'metadata' => ['item_count' => 1],
        ]);

        $job->handle(app(ExportFileWriter::class), app(ExportDatasetRegistry::class));

        $this->assertSame('ready', $artifact->fresh()->status);
        $path = Storage::disk('local')->path($artifact->path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $workbook = $zip->getFromName('xl/workbook.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('Recent snapshots', $workbook);
        $this->assertStringContainsString('12345.6700', $sheet);
        $this->assertDatabaseHas('portfolio_export_notification_outbox', ['artifact_id' => $artifact->id, 'event_type' => 'completed']);
    }

    public function test_worker_failure_marks_artifact_failed_and_publishes_durable_failure_notice(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $artifact = $this->queuedArtifact($owner);
        $failingWriter = new class extends ExportFileWriter {
            public function csv(array $columns, array $rows, string $path, array $metadata = [], ?callable $shouldCancel = null): void
            {
                throw new \RuntimeException('simulated writer failure');
            }
        };
        $job = new GenerateExportArtifact($artifact->id, [
            'dataset' => 'portfolio-snapshots',
            'profile_id' => $profile->id,
            'scope' => 'full',
            'selected' => [],
            'filters' => [],
            'columns' => ['snapshot_date', 'portfolio_value'],
            'metadata' => [],
        ]);

        try {
            $job->handle($failingWriter, app(ExportDatasetRegistry::class));
            $this->fail('Expected the writer failure to be rethrown.');
        } catch (\RuntimeException $error) {
            $this->assertSame('simulated writer failure', $error->getMessage());
        }

        $this->assertSame('failed', $artifact->fresh()->status);
        $this->assertTrue($artifact->fresh()->expires_at->isFuture());
        $this->assertDatabaseHas('portfolio_notification_sources', ['notification_type' => 'export.failed']);
        $failedEvent = ExportNotificationOutbox::query()->where('artifact_id', $artifact->id)->where('event_type', 'failed')->sole();
        $this->assertNotNull($failedEvent->delivered_at);
    }

    public function test_cancellation_after_file_write_prevents_ready_promotion_and_removes_the_final_file(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $artifact = $this->queuedArtifact($owner);
        $job = new GenerateExportArtifact($artifact->id, [
            'dataset' => 'portfolio-snapshots',
            'profile_id' => $profile->id,
            'scope' => 'full',
            'selected' => [],
            'filters' => [],
            'columns' => ['snapshot_date', 'portfolio_value'],
            'metadata' => [],
        ]);
        $cancellingWriter = new class($artifact->id) extends ExportFileWriter {
            public function __construct(private int $artifactId) {}

            public function csv(array $columns, array $rows, string $path, array $metadata = [], ?callable $shouldCancel = null): void
            {
                file_put_contents($path, "finished data\n");
                ExportArtifact::query()->whereKey($this->artifactId)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
        };

        $job->handle($cancellingWriter, app(ExportDatasetRegistry::class));

        $this->assertSame('cancelled', $artifact->fresh()->status);
        Storage::disk('local')->assertMissing($artifact->path);
        Storage::disk('local')->assertMissing($artifact->path.'.partial');
        $this->assertDatabaseMissing('portfolio_notification_sources', ['notification_type' => 'export.completed']);
    }

    public function test_worker_stops_if_artifact_was_cancelled_before_it_started(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $artifact = $this->queuedArtifact($owner, 'cancelled');
        $artifact->update(['cancelled_at' => now()]);
        $job = new GenerateExportArtifact($artifact->id, ['dataset' => 'portfolio-snapshots']);

        $job->handle(app(ExportFileWriter::class), app(ExportDatasetRegistry::class));

        Storage::disk('local')->assertMissing($artifact->path);
        $this->assertSame('cancelled', $artifact->fresh()->status);
        $this->assertDatabaseMissing('portfolio_notification_sources', ['notification_type' => 'export.completed']);
    }

    private function queuedArtifact(User $user, string $status = 'queued'): ExportArtifact
    {
        $token = (string) Str::uuid();
        return ExportArtifact::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => 'exports/'.$user->id.'/'.$token.'.csv',
            'status' => $status,
            'expires_at' => null,
        ]);
    }
}
