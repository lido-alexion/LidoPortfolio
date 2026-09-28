<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\ScreenerVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_change_bumps_semantic_version(): void
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
            'name' => 'Semantic Screener',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated()->json('data');

        $id = (int) $created['id'];
        $this->actingAs($user)->putJson('/api/screeners/'.$id, [
            'name' => 'Semantic Screener',
            'scope' => 'all_equities',
            'definition_json' => $definition,
        ])->assertOk();

        $screener = Screener::query()->findOrFail($id);
        $this->assertSame(2, (int) $screener->artifact_version);
        $this->assertDatabaseHas('portfolio_screener_versions', [
            'screener_id' => $id,
            'version' => 2,
            'scope' => 'all_equities',
        ]);
    }

    public function test_run_pins_screener_version(): void
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
            'name' => 'Run Pin',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated()->json('data');

        $screenerId = (int) $created['id'];
        $version = ScreenerVersion::query()->where('screener_id', $screenerId)->where('version', 1)->firstOrFail();

        $this->actingAs($user)->postJson('/api/screeners/'.$screenerId.'/run')
            ->assertOk()
            ->assertJsonPath('data.screener_version_id', $version->id);

        $run = ScreenerRun::query()->where('screener_id', $screenerId)->latest('id')->firstOrFail();
        $this->assertSame((int) $version->id, (int) $run->screener_version_id);
    }
}
