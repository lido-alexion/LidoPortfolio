<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Services\Strategy\StrategyProvenanceResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyProvenanceResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendation_detail_includes_pinned_screener_versions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $screener = $this->postJson('/api/screeners', [
            'name' => 'Prov Gate',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'roc', 'params' => ['period' => 12]],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 15],
                ],
            ],
        ])->assertCreated()->json('data');

        $strategy = $this->postJson('/api/v1/strategies', [
            'name' => 'Prov Strategy',
        ])->assertCreated()->json('data');

        $editor = $this->getJson('/api/v1/strategy?strategy_id='.$strategy['id'])->assertOk()->json('data');
        $config = $editor['config'];
        $config['eligibility_sources'] = [[
            'screener_id' => (int) $screener['id'],
            'screener_name' => 'Prov Gate',
            'enabled' => true,
            'priority' => 1,
        ]];

        $saved = $this->putJson('/api/v1/strategy?strategy_id='.$strategy['id'], [
            'config' => $config,
        ])->assertOk()->json('data');

        $versionId = (int) $saved['version_id'];
        $pinned = app(StrategyProvenanceResolutionService::class)
            ->pinnedScreenersForStrategyVersion($versionId);
        $this->assertCount(1, $pinned);
        $this->assertSame((int) $screener['id'], $pinned[0]['screener_id']);
        $this->assertNotEmpty($pinned[0]['screener_version_id']);
        $this->assertSame(
            15.0,
            (float) ($pinned[0]['definition_json']['root']['right']['value'] ?? -1)
        );
    }
}
