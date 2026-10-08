<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportArtifact;
use App\Models\ExportBasket;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class V9Data001ExportBasketTest extends TestCase
{
    use RefreshDatabase;

    public function test_basket_configuration_persists_per_account_and_never_exposes_another_accounts_items(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $items = [[
            'dataset' => 'portfolio-snapshots',
            'scope' => 'current',
            'fields' => ['snapshot_date', 'portfolio_value'],
            'filters' => ['range' => '90d', 'sort_by' => 'snapshot_date', 'sort_direction' => 'desc'],
            'sheet_name' => 'Recent performance',
        ]];

        $this->actingAs($owner)->getJson('/api/exports/basket')
            ->assertOk()
            ->assertJsonPath('data.items', []);

        $this->actingAs($owner)->putJson('/api/exports/basket', ['items' => $items])
            ->assertOk()
            ->assertJsonPath('data.items.0.dataset', 'portfolio-snapshots')
            ->assertJsonPath('data.items.0.scope', 'current')
            ->assertJsonPath('data.items.0.fields', ['snapshot_date', 'portfolio_value'])
            ->assertJsonPath('data.items.0.filters.range', '90d')
            ->assertJsonPath('data.items.0.sheet_name', 'Recent performance');

        $this->actingAs($owner)->getJson('/api/exports/basket')
            ->assertOk()
            ->assertJsonPath('data.items.0.dataset', 'portfolio-snapshots')
            ->assertJsonPath('data.items.0.scope', 'current')
            ->assertJsonPath('data.items.0.fields', ['snapshot_date', 'portfolio_value'])
            ->assertJsonPath('data.items.0.filters.range', '90d')
            ->assertJsonPath('data.items.0.sheet_name', 'Recent performance');

        $this->actingAs($other)->getJson('/api/exports/basket')
            ->assertOk()
            ->assertJsonPath('data.items', []);

        $this->assertDatabaseCount('portfolio_export_baskets', 2);
        $stored = ExportBasket::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertEquals($items, $stored->items);
        $this->assertArrayNotHasKey('rows', $stored->items[0]);
        $this->assertArrayNotHasKey('data', $stored->items[0]);
    }

    public function test_basket_item_limit_rejects_the_whole_update_without_changing_saved_configuration(): void
    {
        $user = User::factory()->create();
        $savedItems = [['dataset' => 'portfolio-snapshots', 'scope' => 'full', 'fields' => ['snapshot_date']]];
        $this->actingAs($user)->putJson('/api/exports/basket', ['items' => $savedItems])->assertOk();

        $tooManyItems = array_map(
            fn (int $index) => ['dataset' => 'portfolio-snapshots', 'sheet_name' => 'Sheet '.$index],
            range(1, config('exports.max_basket_items') + 1),
        );

        $this->actingAs($user)->putJson('/api/exports/basket', ['items' => $tooManyItems])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertEquals($savedItems, ExportBasket::query()->where('user_id', $user->id)->firstOrFail()->items);
    }

    public function test_large_export_queues_before_loading_dataset_rows_into_the_request(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['exports.sync_rows' => 0]);
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($owner)->withProfileHeader($owner, $profile)->postJson('/api/exports', [
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'scope' => 'full',
            'fields' => ['snapshot_date', 'portfolio_value'],
        ]);

        $response->assertAccepted()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(GenerateExportArtifact::class);
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql) => str_contains($sql, 'portfolio_snapshots') && str_contains($sql, 'snapshot_date')),
            'The request should estimate count and dispatch work without selecting snapshot rows.',
        );
    }

    public function test_large_basket_is_queued_without_resolving_rows_in_the_request(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['exports.sync_rows' => 0]);
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
        $this->actingAs($owner)->putJson('/api/exports/basket', [
            'items' => [['dataset' => 'portfolio-snapshots', 'scope' => 'full', 'fields' => ['snapshot_date', 'portfolio_value']]],
        ])->assertOk();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($owner)->withProfileHeader($owner, $profile)->postJson('/api/exports/basket/export');

        $response->assertAccepted()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(GenerateExportArtifact::class);
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql) => str_contains($sql, 'portfolio_snapshots') && str_contains($sql, 'snapshot_date')),
            'A large basket should queue without selecting snapshot rows in the request.',
        );
    }

    public function test_basket_read_write_and_export_endpoints_require_authentication(): void
    {
        $this->getJson('/api/exports/basket')->assertUnauthorized();
        $this->putJson('/api/exports/basket', ['items' => []])->assertUnauthorized();
        $this->postJson('/api/exports/basket/export')->assertUnauthorized();
    }
}
