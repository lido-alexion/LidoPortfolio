<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\ScreenerBacktestDay;
use App\Models\ScreenerVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerBacktestVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_edit_preserves_prior_version_backtest_cache_rows(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close'],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 0],
            ],
        ];

        $created = $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'Backtest cache',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated()->json('data');

        $screenerId = (int) $created['id'];
        $v1 = ScreenerVersion::query()->where('screener_id', $screenerId)->where('version', 1)->firstOrFail();

        ScreenerBacktestDay::query()->create([
            'screener_id' => $screenerId,
            'screener_version_id' => $v1->id,
            'as_of_date' => '2026-01-02',
            'scanned' => 10,
            'matched' => 2,
        ]);

        $this->actingAs($user)->putJson('/api/screeners/'.$screenerId, [
            'name' => 'Backtest cache',
            'scope' => 'all_equities',
            'definition_json' => $definition,
        ])->assertOk();

        $this->assertDatabaseHas('portfolio_screener_backtest_days', [
            'screener_id' => $screenerId,
            'screener_version_id' => $v1->id,
            'as_of_date' => '2026-01-02',
        ]);

        $v2 = ScreenerVersion::query()->where('screener_id', $screenerId)->where('version', 2)->firstOrFail();
        ScreenerBacktestDay::query()->create([
            'screener_id' => $screenerId,
            'screener_version_id' => $v2->id,
            'as_of_date' => '2026-01-02',
            'scanned' => 20,
            'matched' => 4,
        ]);

        $this->assertSame(2, ScreenerBacktestDay::query()->where('screener_id', $screenerId)->count());
        $this->assertDatabaseHas('portfolio_screener_backtest_days', [
            'screener_id' => $screenerId,
            'screener_version_id' => $v1->id,
            'as_of_date' => '2026-01-02',
            'scanned' => 10,
            'matched' => 2,
        ]);
        $this->assertDatabaseHas('portfolio_screener_backtest_days', [
            'screener_id' => $screenerId,
            'screener_version_id' => $v2->id,
            'as_of_date' => '2026-01-02',
            'scanned' => 20,
            'matched' => 4,
        ]);
    }

    public function test_matrix_get_does_not_create_missing_current_snapshot_or_relabel_historical_cache(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $created = $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'Snapshot gap',
            'scope' => 'holdings',
            'definition_json' => ['root' => ['type' => 'condition', 'left' => ['indicator' => 'close'], 'operator' => 'gt', 'right' => ['type' => 'constant', 'value' => 0]]],
        ])->assertCreated()->json('data');

        $screenerId = (int) $created['id'];
        $screener = Screener::query()->findOrFail($screenerId);
        $v1 = ScreenerVersion::query()->where('screener_id', $screenerId)->where('version', 1)->firstOrFail();
        ScreenerBacktestDay::query()->create([
            'screener_id' => $screenerId,
            'screener_version_id' => $v1->id,
            'as_of_date' => '2026-01-02',
            'scanned' => 10,
            'matched' => 2,
        ]);

        // Simulate a current definition whose immutable v2 snapshot is missing.
        $screener->forceFill(['artifact_version' => 2, 'definition_hash' => str_repeat('a', 64)])->save();
        $screenerBefore = $screener->fresh();
        $versionsBefore = ScreenerVersion::query()->where('screener_id', $screenerId)->orderBy('id')->get()->toArray();

        $this->actingAs($user)->getJson("/api/screeners/{$screenerId}/backtest/matrix")
            ->assertOk()
            ->assertJsonPath('data.columns', [])
            ->assertJsonPath('data.rows', [])
            ->assertJsonPath('data.run_count', 0)
            ->assertJsonPath('data.stock_count', 0);

        $screenerAfter = $screener->fresh();
        $this->assertSame($screenerBefore->artifact_version, $screenerAfter->artifact_version);
        $this->assertSame($screenerBefore->definition_hash, $screenerAfter->definition_hash);
        $this->assertSame($versionsBefore, ScreenerVersion::query()->where('screener_id', $screenerId)->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('portfolio_screener_backtest_days', [
            'screener_id' => $screenerId,
            'screener_version_id' => $v1->id,
            'as_of_date' => '2026-01-02',
        ]);
    }

    public function test_matrix_get_still_returns_cache_for_existing_current_snapshot(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $created = $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'Current snapshot',
            'scope' => 'holdings',
            'definition_json' => ['root' => ['type' => 'condition', 'left' => ['indicator' => 'close'], 'operator' => 'gt', 'right' => ['type' => 'constant', 'value' => 0]]],
        ])->assertCreated()->json('data');

        $screenerId = (int) $created['id'];
        $version = ScreenerVersion::query()->where('screener_id', $screenerId)->where('version', 1)->firstOrFail();
        ScreenerBacktestDay::query()->create([
            'screener_id' => $screenerId,
            'screener_version_id' => $version->id,
            'as_of_date' => '2026-01-02',
            'scanned' => 10,
            'matched' => 2,
        ]);

        $this->actingAs($user)->getJson("/api/screeners/{$screenerId}/backtest/matrix")
            ->assertOk()
            ->assertJsonPath('data.run_count', 1)
            ->assertJsonPath('data.columns.0.id', '2026-01-02');
    }
}
