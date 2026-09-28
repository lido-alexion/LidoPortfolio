<?php

namespace Tests\Feature\V8;

use App\Contracts\MlHistoricalUniverseProvider;
use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\Stock;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Services\ML\ConfiguredHistoricalUniverseProvider;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlHistoricalUniverseProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_provider_resolves_canonical_identity_and_preserves_mapping_metadata(): void
    {
        $stock = Stock::query()->create(['symbol' => 'ARCHIVE', 'exchange' => 'NSE', 'name' => 'Archive Co', 'sector' => 'Technology']);
        $path = storage_path('framework/testing/ml-universe-'.bin2hex(random_bytes(4)).'.json');
        File::put($path, json_encode(['snapshots' => [[
            'effective_from' => '2020-01-01', 'source' => 'nse_archive', 'snapshot_key' => 'nse-2020-01',
            'response_version' => '2020-r2', 'memberships' => [['symbol' => 'ARCHIVE', 'token' => 'old-token', 'exchange' => 'NSE', 'sector' => 'Technology']],
        ]]], JSON_THROW_ON_ERROR));
        config(['ml.historical_universe.archive_path' => $path]);

        $snapshot = app(ConfiguredHistoricalUniverseProvider::class)->snapshotForDate('2020-01-01');
        $this->assertSame($stock->id, $snapshot['memberships'][0]['stock_id']);
        $this->assertSame('old-token', $snapshot['memberships'][0]['provider_token']);
        $this->assertSame('nse-2020-01', $snapshot['snapshot_key']);
        File::delete($path);
    }

    public function test_provider_backfill_retries_transient_failure_and_preserves_empty_snapshot(): void
    {
        $provider = new class implements MlHistoricalUniverseProvider {
            private int $calls = 0;
            public function snapshotForDate(string $date): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new MlHistoricalUniverseProviderException('temporary timeout', true);
                }
                return ['effective_from' => $date, 'source' => 'test-provider', 'snapshot_key' => 'empty-'.$date, 'response_version' => 'v1', 'memberships' => []];
            }
        };

        $result = app(MlHistoricalUniverseMembershipService::class)->backfillFromProvider(['2021-01-01'], $provider, 'test-provider', 2);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['2021-01-01'], $result['processed_dates']);
        $this->assertSame(['2021-01-01' => 2], $result['retry_counts']);
        $this->assertSame(0, MlUniverseSnapshotBackfillRun::query()->latest('id')->firstOrFail()->failed_dates ? count(MlUniverseSnapshotBackfillRun::query()->latest('id')->firstOrFail()->failed_dates) : 0);
    }

    public function test_provider_failure_is_durable_and_does_not_create_a_zero_member_snapshot(): void
    {
        $provider = new class implements MlHistoricalUniverseProvider {
            public function snapshotForDate(string $date): array
            {
                throw new MlHistoricalUniverseProviderException('rate limited', true);
            }
        };
        $result = app(MlHistoricalUniverseMembershipService::class)->backfillFromProvider(['2021-02-01'], $provider, 'test-provider', 2);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(['2021-02-01'], $result['failed_dates']);
        $this->assertSame(['2021-02-01' => 2], $result['retry_counts']);
        $this->assertSame([], app(MlHistoricalUniverseMembershipService::class)->coverageForDates(['2021-02-01'])['covered_dates']);
    }
}
