<?php

namespace Tests\Feature\V8;

use App\Jobs\MlAcceptanceJob;
use App\Models\User;
use App\Models\V8\MlAcceptanceCampaign;
use App\Services\ML\MlAcceptanceRuntime;
use App\Services\ML\MlAcceptanceSourceService;
use App\Services\ML\NseAcceptanceSourceBootstrapService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NseAcceptanceSourceBootstrapTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sourceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
            'ml.historical_universe.nse_archives_base_url' => 'https://nsearchives.nseindia.com',
        ]);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceIds as $id) {
            File::deleteDirectory(storage_path('app/private/ml-acceptance/sources/'.$id));
        }
        parent::tearDown();
    }

    public function test_campaign_reference_dates_are_deduplicated_and_sorted(): void
    {
        $campaign = MlAcceptanceCampaign::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30', 'status' => 'blocked',
            'identity' => [], 'active_models' => [], 'history' => [], 'horizons' => [
                '1m' => ['reference_dates' => ['2026-08-28', '2026-08-21']],
                '3m' => ['reference_dates' => ['2026-08-28', '2026-07-31']],
                '6m' => ['reference_dates' => ['2026-07-31']],
            ],
        ]);

        $this->assertSame(
            ['2026-07-31', '2026-08-21', '2026-08-28'],
            app(NseAcceptanceSourceBootstrapService::class)->referenceDates($campaign),
        );
    }

    public function test_descriptor_selects_legacy_and_udiff_official_paths(): void
    {
        $service = app(NseAcceptanceSourceBootstrapService::class);
        $this->assertSame(
            'https://nsearchives.nseindia.com/content/historical/EQUITIES/2022/AUG/cm01AUG2022bhav.csv.zip',
            $service->descriptor('2022-08-01')['url'],
        );
        $this->assertSame(
            'https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20240708_F_0000.csv.zip',
            $service->descriptor('2024-07-08')['url'],
        );
    }

    public function test_official_download_uses_existing_immutable_source_pipeline(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->zip('cm01AUG2022bhav.csv', "SYMBOL,SERIES,ISIN,TIMESTAMP\nTEST,EQ,INE123,01-AUG-2022\n");
        Http::fake([
            'nsearchives.nseindia.com/*' => Http::response($payload, 200, ['Content-Type' => 'application/zip']),
        ]);

        $source = app(NseAcceptanceSourceBootstrapService::class)->stage('2022-08-01', $admin->id);
        $this->sourceIds[] = $source->id;
        $this->assertSame('queued', $source->status);
        $this->assertSame('cm01AUG2022bhav.csv.zip', $source->manifest['filename']);
        $this->assertSame(hash('sha256', $payload), $source->manifest['sha256']);

        app(MlAcceptanceSourceService::class)->validateQueued($source->id);
        $source->refresh();
        $this->assertSame('sealed', $source->status);
        $this->assertSame('2022-08-01', $source->evidence['validated_date']);
        Bus::assertDispatched(MlAcceptanceJob::class, fn (MlAcceptanceJob $job) => $job->connection === MlAcceptanceRuntime::CONNECTION
            && $job->queue === MlAcceptanceRuntime::QUEUE);
    }

    public function test_non_zip_response_is_rejected_before_source_creation(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake(['nsearchives.nseindia.com/*' => Http::response('<html>blocked</html>', 200)]);

        try {
            app(NseAcceptanceSourceBootstrapService::class)->stage('2022-08-01', $admin->id);
            $this->fail('Expected a non-ZIP response rejection.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not a supported ZIP', $exception->getMessage());
        }
        $this->assertDatabaseCount('stox_ml_acceptance_sources', 0);
    }

    private function zip(string $name, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nse-bootstrap-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString($name, $contents);
        $zip->close();
        try {
            return (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
