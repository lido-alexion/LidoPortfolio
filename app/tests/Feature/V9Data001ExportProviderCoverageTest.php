<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\ExportArtifact;
use App\Models\Stock;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Export\ExportDatasetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class V9Data001ExportProviderCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_growth_export_includes_chart_series_labels_units_and_scope_metadata(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);

        $resolved = app(ExportDatasetRegistry::class)->resolve('portfolio-growth', $profile, [
            'range' => '180d',
            'sort_direction' => 'desc',
        ]);

        $this->assertSame(['snapshot_date', 'portfolio_value', 'invested_value'], $resolved['columns']);
        $this->assertSame('2026-10-07', $resolved['rows'][0]['snapshot_date']);
        $this->assertSame('portfolio-growth', $resolved['metadata']['dataset']);
        $this->assertSame(['range' => '180d'], $resolved['metadata']['filters']);
        $this->assertSame(['by' => 'snapshot_date', 'direction' => 'desc'], $resolved['metadata']['sort']);
        $this->assertSame([
            'type' => 'time_series',
            'x_axis' => ['field' => 'snapshot_date', 'label' => 'Snapshot date', 'format' => 'YYYY-MM-DD'],
            'series' => [
                ['field' => 'portfolio_value', 'label' => 'Portfolio value', 'unit' => 'INR'],
                ['field' => 'invested_value', 'label' => 'Invested value', 'unit' => 'INR'],
            ],
        ], $resolved['metadata']['chart']);
        $this->assertSame(['csv', 'xlsx'], collect(app(ExportDatasetRegistry::class)->catalog())->firstWhere('id', 'portfolio-growth')['formats']);
    }

    public function test_fundamental_export_is_limited_to_current_holdings_and_excludes_internal_revision_fields(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);

        $heldStock = Stock::query()->create([
            'symbol' => 'OWNED',
            'exchange' => 'NSE',
            'name' => 'Owned Co',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $otherStock = Stock::query()->create([
            'symbol' => 'OTHER',
            'exchange' => 'NSE',
            'name' => 'Other Co',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $unheldStock = Stock::query()->create([
            'symbol' => 'UNHELD',
            'exchange' => 'BSE',
            'name' => 'Unheld Co',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Holding::query()->create(['profile_id' => $profile->id, 'stock_id' => $heldStock->id, 'quantity' => '2.0000']);
        Holding::query()->create(['profile_id' => $otherProfile->id, 'stock_id' => $otherStock->id, 'quantity' => '4.0000']);
        Holding::query()->create(['profile_id' => $profile->id, 'stock_id' => $unheldStock->id, 'quantity' => '0.0000']);

        $this->insertFact($heldStock->id, 'revenue', '123456.789123', true, 1);
        $this->insertFact($otherStock->id, 'net_income', '999999.000000', true, 2);
        $this->insertFact($unheldStock->id, 'equity', '777777.000000', true, 3);
        $this->insertFact($heldStock->id, 'revenue', '1.000000', false, 4);
        $this->insertFact($heldStock->id, 'future_fact', '2.000000', true, 5, '2027-01-01');

        $registry = app(ExportDatasetRegistry::class);
        $estimate = $registry->estimate('portfolio-fundamental-facts', $profile);
        $resolved = $registry->resolve('portfolio-fundamental-facts', $profile);

        $this->assertSame(['rows' => 1, 'exact' => true], $estimate);
        $this->assertSame(['symbol', 'exchange', 'statement_type', 'cadence', 'statement_basis', 'fact_key', 'period_start', 'period_end', 'reported_period', 'value', 'currency', 'availability_date', 'source_provider'], $resolved['columns']);
        $this->assertSame('OWNED', $resolved['rows'][0]['symbol']);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertEqualsWithDelta(123456.789123, (float) $resolved['rows'][0]['value'], 0.0000001);
        } else {
            $this->assertSame('123456.789123', $resolved['rows'][0]['value']);
        }
        $this->assertSame(['1'], $resolved['identities']);
        $this->assertArrayNotHasKey('revision_hash', $resolved['rows'][0]);
        $this->assertArrayNotHasKey('source_meta', $resolved['rows'][0]);
        $this->assertSame(['full'], collect($registry->catalog())->firstWhere('id', 'portfolio-fundamental-facts')['scopes']);
        $this->assertFalse($registry->supportsScope('portfolio-fundamental-facts', 'selected'));

        Storage::fake('local');
        $response = $this->actingAs($owner)->withProfileHeader($owner, $profile)->postJson('/api/exports', [
            'dataset' => 'portfolio-fundamental-facts',
            'format' => 'csv',
            'scope' => 'full',
            'fields' => ['symbol', 'value'],
        ])->assertOk()->assertJsonPath('data.status', 'ready');
        $artifact = ExportArtifact::query()->where('token', $response->json('data.token'))->firstOrFail();
        $csv = Storage::disk('local')->get($artifact->path);
        $this->assertStringContainsString('OWNED', $csv);
        $this->assertStringContainsString('123456.789123', $csv);
        $this->assertStringNotContainsString('OTHER', $csv);
        $this->assertStringNotContainsString('UNHELD', $csv);
    }

    public function test_fundamental_export_checks_profile_ownership(): void
    {
        $profile = $this->defaultPortfolioFor(User::factory()->create());

        try {
            app(ExportDatasetRegistry::class)->assertAuthorized('portfolio-fundamental-facts', $profile, $profile->user_id + 1);
            $this->fail('Expected cross-account authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function insertFact(int $stockId, string $factKey, string $value, bool $current, int $id, string $availabilityDate = '2026-05-01'): void
    {
        DB::table('stox_fundamental_facts')->insert([
            'id' => $id,
            'stock_id' => $stockId,
            'provider' => 'fixture',
            'statement_type' => 'income',
            'cadence' => 'annual',
            'statement_basis' => 'consolidated',
            'fact_key' => $factKey,
            'period_start' => '2025-04-01',
            'period_end' => '2026-03-31',
            'reported_period' => '2026-03-31',
            'value' => $value,
            'currency' => 'INR',
            'availability_date' => $availabilityDate,
            'first_fetched_at' => '2026-05-02 00:00:00',
            'revision_hash' => str_repeat((string) $id, 64),
            'revision_number' => 1,
            'is_current' => $current,
            'source_meta' => json_encode(['internal' => 'not-exported']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
