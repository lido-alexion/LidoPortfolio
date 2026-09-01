<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use App\Services\EquityUniverseService;
use App\Services\ProviderResolverService;
use App\Services\StockMasterSyncService;
use App\Services\StockValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeUser(bool $isAdmin = false): User
    {
        $user = User::query()->create([
            'name' => 'Stock User',
            'email' => 'stock-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        if ($isAdmin) {
            $user->is_admin = true;
            $user->save();
        }

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createStock(array $attributes = []): Stock
    {
        $adminDeactivated = (bool) ($attributes['admin_deactivated'] ?? false);
        unset($attributes['admin_deactivated']);

        $stock = Stock::query()->create(array_merge([
            'is_benchmark' => false,
        ], $attributes));

        if ($adminDeactivated) {
            $stock->admin_deactivated = true;
            $stock->save();
        }

        return $stock->fresh();
    }

    public function test_migration_defaults_admin_deactivated_to_false(): void
    {
        $this->assertTrue(Schema::hasColumn('portfolio_stocks', 'admin_deactivated'));

        $stock = Stock::query()->create([
            'symbol' => 'MIGDEF',
            'exchange' => 'NSE',
            'name' => 'Migration Default',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->assertFalse($stock->fresh()->admin_deactivated);
    }

    public function test_non_admin_cannot_create_stock_via_store_endpoint(): void
    {
        $user = $this->makeUser(false);
        $stock = Stock::query()->create([
            'symbol' => 'ZZZTEST',
            'exchange' => 'NSE',
            'name' => 'Test',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/stocks', [
                'symbol' => 'NEWTEST',
                'exchange' => 'NSE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson("/api/stocks/{$stock->id}", ['name' => 'Changed'])
            ->assertForbidden();
    }

    public function test_admin_can_update_stock_metadata(): void
    {
        $admin = $this->makeUser(true);
        $stock = Stock::query()->create([
            'symbol' => 'ADMINTST',
            'exchange' => 'NSE',
            'name' => 'Before',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/stocks/{$stock->id}", ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.name', 'After');
    }

    public function test_admin_deactivate_sets_override_without_changing_is_active(): void
    {
        $admin = $this->makeUser(true);
        $stock = Stock::query()->create([
            'symbol' => 'DEACT1',
            'exchange' => 'NSE',
            'name' => 'Deactivate Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/stocks/{$stock->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.admin_deactivated', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.effective_active', false);

        $fresh = $stock->fresh();
        $this->assertTrue($fresh->admin_deactivated);
        $this->assertTrue($fresh->is_active);
    }

    public function test_admin_activate_clears_override_without_changing_is_active(): void
    {
        $admin = $this->makeUser(true);
        $stock = $this->createStock([
            'symbol' => 'ACT1',
            'exchange' => 'NSE',
            'name' => 'Activate Me',
            'is_active' => false,
            'admin_deactivated' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/stocks/{$stock->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.admin_deactivated', false)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.effective_active', false);

        $fresh = $stock->fresh();
        $this->assertFalse($fresh->admin_deactivated);
        $this->assertFalse($fresh->is_active);
    }

    public function test_activate_and_deactivate_actions_are_admin_gated(): void
    {
        $user = $this->makeUser(false);
        $stock = Stock::query()->create([
            'symbol' => 'GATED',
            'exchange' => 'NSE',
            'name' => 'Gated',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user)
            ->postJson("/api/stocks/{$stock->id}/deactivate")
            ->assertForbidden();

        $stock->admin_deactivated = true;
        $stock->save();

        $this->actingAs($user)
            ->postJson("/api/stocks/{$stock->id}/activate")
            ->assertForbidden();
    }

    public function test_inactive_stock_can_be_addressed_by_admin_actions(): void
    {
        $admin = $this->makeUser(true);
        $stock = Stock::query()->create([
            'symbol' => 'INACT',
            'exchange' => 'NSE',
            'name' => 'Inactive',
            'is_active' => false,
            'is_benchmark' => false,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/stocks/{$stock->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.admin_deactivated', true);
    }

    public function test_admin_catalogue_returns_inactive_and_admin_deactivated_rows(): void
    {
        $admin = $this->makeUser(true);
        $active = Stock::query()->create([
            'symbol' => 'ACTIVE1',
            'exchange' => 'NSE',
            'name' => 'Active',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $systemInactive = Stock::query()->create([
            'symbol' => 'SYSOFF',
            'exchange' => 'NSE',
            'name' => 'System Off',
            'is_active' => false,
            'is_benchmark' => false,
        ]);
        $adminOff = $this->createStock([
            'symbol' => 'ADMOff',
            'exchange' => 'NSE',
            'name' => 'Admin Off',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/stocks?status=all&per_page=50')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertContains($systemInactive->id, $ids);
        $this->assertContains($adminOff->id, $ids);
    }

    public function test_admin_catalogue_supports_search_and_pagination(): void
    {
        $admin = $this->makeUser(true);
        foreach (range(1, 3) as $index) {
            Stock::query()->create([
                'symbol' => 'SRCH'.$index,
                'exchange' => 'NSE',
                'name' => 'Searchable '.$index,
                'is_active' => true,
                'is_benchmark' => false,
            ]);
        }
        Stock::query()->create([
            'symbol' => 'OTHER',
            'exchange' => 'NSE',
            'name' => 'Different',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/stocks?q=SRCH&per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)
            ->getJson('/api/admin/stocks?q=SRCH&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_catalogue_status_filters(): void
    {
        $admin = $this->makeUser(true);
        Stock::query()->create([
            'symbol' => 'EFFACT',
            'exchange' => 'NSE',
            'name' => 'Effective Active',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'SYSIN',
            'exchange' => 'NSE',
            'name' => 'System Inactive',
            'is_active' => false,
            'is_benchmark' => false,
        ]);
        $this->createStock([
            'symbol' => 'ADMDE',
            'exchange' => 'NSE',
            'name' => 'Admin Deactivated',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/stocks?status=active')
            ->assertOk()
            ->assertJsonFragment(['symbol' => 'EFFACT'])
            ->assertJsonMissing(['symbol' => 'SYSIN'])
            ->assertJsonMissing(['symbol' => 'ADMDE']);

        $this->actingAs($admin)
            ->getJson('/api/admin/stocks?status=inactive')
            ->assertOk()
            ->assertJsonFragment(['symbol' => 'SYSIN'])
            ->assertJsonMissing(['symbol' => 'EFFACT']);

        $this->actingAs($admin)
            ->getJson('/api/admin/stocks?status=admin_deactivated')
            ->assertOk()
            ->assertJsonFragment(['symbol' => 'ADMDE'])
            ->assertJsonMissing(['symbol' => 'EFFACT']);
    }

    public function test_non_admin_cannot_access_admin_catalogue(): void
    {
        $user = $this->makeUser(false);

        $this->actingAs($user)
            ->getJson('/api/admin/stocks')
            ->assertForbidden();
    }

    public function test_public_stock_search_does_not_leak_admin_deactivated_rows(): void
    {
        $user = $this->makeUser(false);
        $this->createStock([
            'symbol' => 'HIDEME',
            'exchange' => 'NSE',
            'name' => 'Hidden Stock',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/stocks/search?q=hide')
            ->assertOk()
            ->assertJsonMissing(['symbol' => 'HIDEME']);
    }

    public function test_public_stock_index_does_not_leak_admin_deactivated_rows(): void
    {
        $user = $this->makeUser(false);
        $this->createStock([
            'symbol' => 'HIDIDX',
            'exchange' => 'NSE',
            'name' => 'Hidden Index',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/stocks?q=HIDIDX')
            ->assertOk()
            ->assertJsonMissing(['symbol' => 'HIDIDX']);
    }

    public function test_admin_deactivated_stock_survives_stock_master_upsert(): void
    {
        $stock = $this->createStock([
            'symbol' => 'SYNC1',
            'exchange' => 'NSE',
            'name' => 'Before Sync',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $service = $this->makeStockMasterSyncService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('upsertMasterRow');
        $method->setAccessible(true);
        $method->invoke($service, 'SYNC1', 'NSE', 'After Sync', 'INE000000099', 'EQ');

        $fresh = $stock->fresh();
        $this->assertTrue($fresh->admin_deactivated);
        $this->assertTrue($fresh->is_active);
        $this->assertFalse($fresh->isEffectivelyActive());
    }

    public function test_admin_deactivated_stock_survives_provider_validation(): void
    {
        $stock = $this->createStock([
            'symbol' => 'PROV1',
            'exchange' => 'NSE',
            'name' => 'Provider Stock',
            'is_active' => true,
            'admin_deactivated' => true,
            'last_verified_at' => now()->subDays(30),
        ]);

        Http::fake([
            'https://www.nseindia.com' => Http::response('<html></html>', 200),
            'https://www.nseindia.com/api/quote-equity*' => Http::response([
                'info' => ['companyName' => 'Provider Stock', 'isin' => 'INE000000088'],
            ], 200),
        ]);

        $service = app(StockValidationService::class);
        $result = $service->validateAndPersist('PROV1', 'NSE');

        $this->assertTrue($result->valid);
        $fresh = $stock->fresh();
        $this->assertTrue($fresh->admin_deactivated);
        $this->assertTrue($fresh->is_active);
    }

    public function test_resolve_canonical_stock_still_finds_admin_deactivated_row_for_transactions(): void
    {
        $this->createStock([
            'symbol' => 'TXN1',
            'exchange' => 'NSE',
            'name' => 'Txn Stock',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $resolved = app(EquityUniverseService::class)->resolveCanonicalStock('TXN1', 'NSE');
        $this->assertNotNull($resolved);
        $this->assertSame('TXN1', $resolved->symbol);
    }

    public function test_active_nse_isins_ignores_admin_override_for_bse_dedup(): void
    {
        $this->createStock([
            'symbol' => 'DEDUP',
            'exchange' => 'NSE',
            'name' => 'Dedup NSE',
            'isin' => 'INE111111111',
            'is_active' => true,
            'admin_deactivated' => true,
        ]);

        $isins = app(EquityUniverseService::class)->activeNseIsins();
        $this->assertTrue($isins->contains('INE111111111'));

        $bseVisible = app(EquityUniverseService::class)
            ->searchQuery('BSE')
            ->where('symbol', 'DEDUP')
            ->exists();
        $this->assertFalse($bseVisible);
    }

    protected function makeStockMasterSyncService(): StockMasterSyncService
    {
        $syncLog = Mockery::mock(\App\Services\SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->andReturn(null);
        $syncLog->shouldReceive('log')->andReturnNull();
        $syncLog->shouldReceive('completeRun')->andReturnNull();
        $priceFetch = Mockery::mock(\App\Services\PriceFetchService::class);
        $bseMaster = Mockery::mock(\App\Services\BseEquityMasterService::class);
        $dualListedRepair = Mockery::mock(\App\Services\DualListedNseRepairService::class);

        return new StockMasterSyncService(
            new ProviderResolverService,
            $syncLog,
            $priceFetch,
            $bseMaster,
            $dualListedRepair,
        );
    }
}
