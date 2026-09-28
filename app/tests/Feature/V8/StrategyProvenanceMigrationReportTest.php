<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyProvenanceMigrationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_migration_report_classifies_provenance_rows(): void
    {
        $investor = User::factory()->create();
        $profile = $this->defaultPortfolioFor($investor);
        $this->actingAs($investor)->withProfileHeader($investor);

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $versionId = (int) $active['version_id'];

        $stock = Stock::query()->create([
            'symbol' => 'MIG',
            'exchange' => 'NSE',
            'name' => 'Migrate',
            'is_active' => true,
        ]);

        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $versionId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 1,
            'strategy_score' => 60,
            'confidence' => 0.6,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
        ]);

        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => null,
            'recommendation_type' => TradingRecommendation::ACTION_WATCH,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 2,
            'strategy_score' => 40,
            'confidence' => 0.4,
            'risk_level' => TradingRecommendation::RISK_LOW,
            'generated_at' => now(),
        ]);

        $orphanVersionId = (int) TradingStrategyVersion::query()->max('id') + 9999;
        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $orphanVersionId,
            'recommendation_type' => TradingRecommendation::ACTION_WATCH,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 3,
            'strategy_score' => 30,
            'confidence' => 0.3,
            'risk_level' => TradingRecommendation::RISK_LOW,
            'generated_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/strategy-provenance/migration-report')
            ->assertOk();

        $entity = collect($response->json('data.entities'))
            ->firstWhere('key', 'trading_recommendations.strategy_version');

        $this->assertNotNull($entity);
        $this->assertSame(3, (int) $entity['total']);
        $this->assertSame(1, (int) $entity['resolved']);
        $this->assertSame(1, (int) $entity['unresolved']);
        $this->assertSame(1, (int) $entity['orphaned']);
        $this->assertFalse($response->json('data.gate.not_null_enforcement_recommended'));
    }

    public function test_non_admin_cannot_read_migration_report(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson('/api/v1/admin/strategy-provenance/migration-report')
            ->assertForbidden();
    }
}
