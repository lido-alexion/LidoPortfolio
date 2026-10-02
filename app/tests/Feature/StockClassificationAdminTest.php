<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockClassificationObservation;
use App\Models\StockClassificationOverrideRevision;
use App\Models\StockClassificationRefreshState;
use App\Models\User;
use App\Models\V8\MlUniverseMembership;
use App\Services\StockClassificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockClassificationAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_controls_are_admin_only(): void
    {
        $this->getJson('/api/admin/stock-classifications')->assertUnauthorized();

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->getJson('/api/admin/stock-classifications')->assertForbidden();
    }

    public function test_observations_preserve_source_hash_and_first_observed_timestamp(): void
    {
        $stock = $this->stock('ALPHA');
        $observedAt = Carbon::parse('2026-10-02 10:00:00', 'UTC');
        $payload = $this->payload('Technology', 'Software');

        $classification = app(StockClassificationService::class)->recordObservation($stock, $payload, $observedAt);

        $observation = StockClassificationObservation::query()->firstOrFail();
        $this->assertSame('automatic_observation', $classification['source_type']);
        $this->assertSame(StockClassificationService::PROVIDER, $observation->provider);
        $this->assertSame(StockClassificationService::TAXONOMY, $observation->taxonomy_version);
        $this->assertSame('Technology', $observation->provider_sector);
        $this->assertSame('Software', $observation->provider_industry);
        $this->assertSame('Information Technology', $observation->sector);
        $this->assertSame('IT Services', $observation->industry);
        $this->assertSame(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $observation->raw_evidence_sha256);
        $this->assertSame($observedAt->utc()->toIso8601String(), $observation->first_observed_at->utc()->toIso8601String());

        app(StockClassificationService::class)->recordObservation($stock, $payload, $observedAt->copy()->addDay());
        $this->assertSame(1, StockClassificationObservation::query()->count());
        $stored = StockClassificationObservation::query()->firstOrFail();
        $this->assertSame($observedAt->utc()->toIso8601String(), $stored->first_observed_at->utc()->toIso8601String());
        $this->assertSame($observedAt->copy()->addDay()->utc()->toIso8601String(), $stored->observed_at->utc()->toIso8601String());
    }

    public function test_admin_override_validates_relationship_takes_precedence_and_can_be_removed(): void
    {
        $admin = User::factory()->admin()->create();
        $stock = $this->stock('ALPHA');
        $peer = $this->stock('BETA');
        $service = app(StockClassificationService::class);
        $service->recordObservation($stock, $this->payload('Technology', 'Software'));
        $service->recordObservation($peer, $this->payload('Financials', 'Banks'));

        $this->actingAs($admin)->putJson('/api/admin/stocks/'.$stock->id.'/classification/override', [
            'sector' => 'Information Technology', 'industry' => 'Banks', 'reason' => 'invalid pair',
        ])->assertUnprocessable();

        $this->actingAs($admin)->putJson('/api/admin/stocks/'.$stock->id.'/classification/override', [
            'sector' => 'Financial Services', 'industry' => 'Banks', 'reason' => 'PO-approved fallback',
        ])->assertOk()->assertJsonPath('data.source_type', 'manual_override');

        $this->assertDatabaseHas('stox_stock_classification_override_revisions', ['action' => 'created', 'actor_id' => $admin->id]);
        $this->actingAs($admin)->deleteJson('/api/admin/stocks/'.$stock->id.'/classification/override')
            ->assertOk()->assertJsonPath('data.source_type', 'automatic_observation')
            ->assertJsonPath('data.sector', 'Information Technology');
        $this->assertDatabaseHas('stox_stock_classification_override_revisions', ['action' => 'removed', 'actor_id' => $admin->id]);
    }

    public function test_current_classification_never_mutates_historical_membership(): void
    {
        $admin = User::factory()->admin()->create();
        $stock = $this->stock('HISTORY', ['sector' => null]);
        $membership = MlUniverseMembership::query()->create([
            'stock_id' => $stock->id, 'universe_key' => 'official_nse', 'effective_from' => '2026-10-01',
            'sector_snapshot' => null, 'source' => 'nse_cash_bhavcopy',
        ]);
        $service = app(StockClassificationService::class);
        $service->recordObservation($stock, $this->payload('Technology', 'Software'));
        $service->saveOverride($stock, $admin->id, 'Information Technology', 'IT Services', 'manual');

        $this->assertDatabaseHas('stox_ml_universe_memberships', ['id' => $membership->id, 'sector_snapshot' => null]);
        $this->assertNull($stock->fresh()->sector);
    }

    public function test_approved_taxonomy_is_available_without_provider_observations(): void
    {
        $admin = User::factory()->admin()->create();
        $stock = $this->stock('OUTAGE');

        $this->actingAs($admin)->getJson('/api/admin/stock-classifications/options')
            ->assertOk()
            ->assertJsonPath('data.taxonomy_version', StockClassificationService::TAXONOMY)
            ->assertJsonFragment(['Information Technology'])
            ->assertJsonFragment(['IT Services']);

        $this->actingAs($admin)->putJson('/api/admin/stocks/'.$stock->id.'/classification/override', [
            'sector' => 'Information Technology', 'industry' => 'IT Services', 'reason' => 'provider outage fallback',
        ])->assertOk()->assertJsonPath('data.source_type', 'manual_override');
    }

    public function test_provider_403_is_a_bounded_retry_and_does_not_create_an_observation(): void
    {
        $stock = $this->stock('TCS');
        Http::fake(fn ($request) => str_contains($request->url(), 'quote-equity')
            ? Http::response('<html>Access Denied</html>', 403)
            : Http::response([], 200));

        $this->expectException(\RuntimeException::class);
        try {
            app(StockClassificationService::class)->refresh($stock);
        } finally {
            $this->assertDatabaseCount('stox_stock_classification_observations', 0);
            $this->assertDatabaseHas('stox_stock_classification_refresh_states', [
                'stock_id' => $stock->id, 'attempts' => 1,
            ]);
            $this->assertNotNull(StockClassificationRefreshState::query()->firstOrFail()->next_attempt_at);
        }
    }

    public function test_refresh_command_retries_unknown_ipo_and_refreshes_old_observations(): void
    {
        $ipo = $this->stock('IPOONE', ['created_at' => Carbon::parse('2026-10-02 09:00:00')]);
        $old = $this->stock('OLDONE', ['created_at' => Carbon::parse('2026-09-01 09:00:00')]);
        app(StockClassificationService::class)->recordObservation($old, $this->payload('Technology', 'Software'), Carbon::parse('2026-09-01'));
        Http::fake(fn ($request) => str_contains($request->url(), 'quote-equity')
            ? Http::response(['industryInfo' => $this->payload('Technology', 'Software')['industryInfo']], 200)
            : Http::response([], 200));

        $this->artisan('stox:refresh-stock-classifications', ['--batch' => 2])->assertExitCode(0);
        $this->assertDatabaseHas('stox_stock_classification_observations', ['stock_id' => $ipo->id, 'provider' => StockClassificationService::PROVIDER]);
        $this->assertDatabaseHas('stox_stock_classification_observations', ['stock_id' => $old->id, 'provider' => StockClassificationService::PROVIDER]);
    }

    private function stock(string $symbol, array $extra = []): Stock
    {
        return Stock::query()->create(array_merge([
            'symbol' => $symbol, 'exchange' => 'NSE', 'name' => $symbol.' Limited', 'is_active' => true, 'is_benchmark' => false,
        ], $extra));
    }

    /** @return array<string,mixed> */
    private function payload(string $sector, string $industry): array
    {
        return ['industryInfo' => ['macro' => 'Macro', 'sector' => $sector, 'industry' => $industry, 'basicIndustry' => $industry.' Basic']];
    }
}
