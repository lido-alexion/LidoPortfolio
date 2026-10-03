<?php

namespace Tests\Feature\V8;

use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\Stock;
use App\Services\ML\MlAcceptanceReportService;
use App\Services\ML\NseHistoricalIdentityEvidence;
use App\Services\ML\NseHistoricalUniverseArchiveProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NseHistoricalIdentityEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_subdivision_maps_only_within_the_evidenced_interval(): void
    {
        $stock = Stock::query()->create(['symbol' => 'NESTLEIND', 'exchange' => 'NSE', 'isin' => 'INE239A01024', 'name' => 'Nestle India Limited']);
        foreach (['2022-11-04', '2024-01-04'] as $date) {
            $snapshot = $this->snapshot($date, 'NESTLEIND', 'INE239A01016');
            $this->assertSame($stock->id, $snapshot['memberships'][0]['stock_id']);
            $this->assertSame('NESTLEIND', $snapshot['memberships'][0]['provider_symbol']);
            $this->assertNull($snapshot['memberships'][0]['sector_snapshot']);
            $this->assertSame(1, $snapshot['diagnostics']['historical_identity_mapped_count']);
            $this->assertSame(app(NseHistoricalIdentityEvidence::class)->hash(), $snapshot['diagnostics']['identity_evidence_sha256']);
        }
        foreach (['2022-11-03', '2024-01-05'] as $date) {
            $this->assertRejected($date, 'NESTLEIND', 'INE239A01016', 'conflicting_symbol_isin_without_dated_evidence');
        }
        $this->assertSame('INE239A01024', $stock->fresh()->isin);
        $this->assertDatabaseCount('stox_ml_universe_snapshot_boundaries', 0);
        $this->assertDatabaseCount('stox_ml_universe_memberships', 0);
    }

    public function test_reused_symbol_cannot_substitute_for_the_documented_canonical_isin(): void
    {
        Stock::query()->create(['symbol' => 'NESTLEIND', 'exchange' => 'NSE', 'isin' => 'INE000000002', 'name' => 'Different security']);
        $this->assertRejected('2022-11-04', 'NESTLEIND', 'INE239A01016', 'historical_target_missing_or_ambiguous');
        $this->assertRejected('2022-11-04', 'NESTLEIND', 'INE000000001', 'conflicting_symbol_isin_without_dated_evidence');
    }

    public function test_missing_provenance_does_not_authorize_a_historical_alias(): void
    {
        Stock::query()->create(['symbol' => 'NESTLEIND', 'exchange' => 'NSE', 'isin' => 'INE239A01024', 'name' => 'Nestle India Limited']);
        $document = app(NseHistoricalIdentityEvidence::class)->document();
        $document['identities'][0]['evidence'] = [];
        $this->bindDocument($document);
        $this->assertRejected('2022-11-04', 'NESTLEIND', 'INE239A01016', 'conflicting_symbol_isin_without_dated_evidence');
    }

    public function test_overlapping_evidence_and_wrong_historical_symbol_fail_closed(): void
    {
        Stock::query()->create(['symbol' => 'NESTLEIND', 'exchange' => 'NSE', 'isin' => 'INE239A01024', 'name' => 'Nestle India Limited']);
        $document = app(NseHistoricalIdentityEvidence::class)->document();
        $document['identities'][] = $document['identities'][0];
        $this->bindDocument($document);
        $this->assertRejected('2022-11-04', 'NESTLEIND', 'INE239A01016', 'conflicting_symbol_isin_without_dated_evidence');
        $this->assertRejected('2022-11-04', 'UNPROVEN', 'INE239A01016', 'no_current_identity_candidate');
    }

    public function test_duplicate_current_and_historical_targets_are_not_selected_by_row_order(): void
    {
        foreach (['NESTLEIND', 'OTHER'] as $symbol) {
            Stock::query()->create(['symbol' => $symbol, 'exchange' => 'NSE', 'isin' => 'INE239A01024', 'name' => $symbol]);
        }
        $this->assertRejected('2024-01-05', 'NESTLEIND', 'INE239A01024', 'ambiguous_current_isin');
        $this->assertRejected('2022-11-04', 'NESTLEIND', 'INE239A01016', 'historical_target_missing_or_ambiguous');
    }

    public function test_public_failure_diagnostics_preserve_counts_without_source_paths_or_identifiers(): void
    {
        $safe = app(MlAcceptanceReportService::class)->safe(['diagnostics' => [
            'nse_source_file' => '/private/source.csv',
            'unmapped_identifiers' => ['INE000000001'],
            'unmapped_reason_counts' => ['ambiguous_current_isin' => 4],
            'mapping_percentage' => 86.22,
        ]]);
        $this->assertSame(['diagnostics' => [
            'unmapped_reason_counts' => ['ambiguous_current_isin' => 4],
            'mapping_percentage' => 86.22,
        ]], $safe);
    }

    private function bindDocument(array $document): void
    {
        $this->app->instance(NseHistoricalIdentityEvidence::class, new class($document) extends NseHistoricalIdentityEvidence
        {
            public function __construct(private array $evidence) {}

            public function document(): array
            {
                return $this->evidence;
            }
        });
    }

    private function snapshot(string $date, string $symbol, string $isin): array
    {
        $path = storage_path('framework/testing/identity-'.$date.'.csv');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "SYMBOL,SERIES,ISIN\n{$symbol},EQ,{$isin}\n");
        try {
            return app(NseHistoricalUniverseArchiveProvider::class)->stagedSnapshot($path, 'nse_cash_bhavcopy', $date);
        } finally {
            File::delete($path);
        }
    }

    private function assertRejected(string $date, string $symbol, string $isin, string $reason): void
    {
        try {
            $this->snapshot($date, $symbol, $isin);
            $this->fail('Mapping must fail the unchanged quality gate.');
        } catch (MlHistoricalUniverseProviderException $e) {
            $this->assertStringContainsString('below 90%', $e->getMessage());
            $this->assertSame(0, $e->diagnostics['mapped_count']);
            $this->assertSame([$reason => 1], $e->diagnostics['unmapped_reason_counts']);
        }
    }
}
