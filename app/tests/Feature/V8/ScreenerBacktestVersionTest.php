<?php

namespace Tests\Feature\V8;

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
    }
}
