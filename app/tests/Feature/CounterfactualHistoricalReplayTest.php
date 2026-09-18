<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBindingRevision;
use App\Models\PortfolioReplayRun;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use App\Services\Simulation\PortfolioReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CounterfactualHistoricalReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_default_and_explicit_counterfactual_versions_are_pinned_separately(): void
    {
        [$user, $profile, $binding, $v1, $v2, $v3] = $this->fixture();
        $service = app(PortfolioReplayService::class);
        $base = [
            'starting_mode' => 'historical_branch', 'period_start' => '2026-01-02', 'period_end' => '2026-01-02',
            'price_method' => 'next_open', 'adverse_slippage_percent' => 0,
        ];

        $default = $service->readiness($profile, $base);
        $this->assertSame('ready', $default['status']);
        $this->assertSame($v1->id, $default['pinned_world']['binding_revisions'][0]['artifact_version_id']);
        $this->assertSame($v1->id, $default['pinned_world']['binding_revisions'][0]['historical_artifact_version_id']);
        $this->assertFalse($default['pinned_world']['counterfactual']['is_counterfactual']);

        $counterfactual = $service->readiness($profile, $base + [
            'strategy_version_overrides' => [(string) $binding->id => $v2->id],
        ]);
        $this->assertSame('ready', $counterfactual['status']);
        $row = $counterfactual['pinned_world']['binding_revisions'][0];
        $this->assertSame($v1->id, $row['historical_artifact_version_id']);
        $this->assertSame($v2->id, $row['selected_artifact_version_id']);
        $this->assertSame($v2->id, $row['artifact_version_id']);
        $this->assertTrue($row['is_counterfactual']);
        $this->assertSame($v1->id, $counterfactual['pinned_world']['counterfactual']['overrides'][0]['historical_artifact_version_id']);
        $this->assertSame($v2->id, $counterfactual['pinned_world']['counterfactual']['overrides'][0]['selected_artifact_version_id']);
        $this->assertSame($default['starting_state'], $counterfactual['starting_state']);
        $this->assertNotSame($v3->id, $row['artifact_version_id']);

        $run = $service->create($profile, $user->id, $base + [
            'strategy_version_overrides' => [(string) $binding->id => $v2->id],
        ]);
        $this->assertSame($v2->id, $run->fresh()->pinned_world['binding_revisions'][0]['artifact_version_id']);
        $this->assertTrue($run->fresh()->pinned_world['counterfactual']['is_counterfactual']);

        $this->assertSame($v2->id, $run->fresh()->pinned_world['binding_revisions'][0]['artifact_version_id']);
        $this->assertSame($v3->id, $binding->fresh()->activeRevision->artifact_version_id);
    }

    public function test_partial_override_keeps_other_historical_strategy_and_invalid_versions_fail_closed(): void
    {
        [$user, $profile, $binding, $v1, $v2] = $this->fixture();
        $otherArtifact = ReusableArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(), 'owner_user_id' => $user->id, 'artifact_type' => ArtifactType::SCREENER,
            'slug' => 'wrong-type', 'name' => 'Wrong Type', 'origin' => 'authored',
        ]);
        $wrongType = ReusableArtifactVersion::query()->create([
            'artifact_id' => $otherArtifact->id, 'semver' => '1.0.0', 'status' => ReusableArtifactVersion::STATUS_PUBLISHED,
            'content_json' => ['artifact_type' => ArtifactType::SCREENER, 'definition' => []],
            'definition_hash' => hash('sha256', 'wrong-type'), 'created_by_user_id' => $user->id, 'published_at' => now(),
        ]);
        $draft = app(ReusableArtifactLifecycleService::class)->createNextDraft($v1, $user, '4.0.0');
        $service = app(PortfolioReplayService::class);
        $base = [
            'starting_mode' => 'historical_branch', 'period_start' => '2026-01-02', 'period_end' => '2026-01-02',
            'price_method' => 'next_open', 'adverse_slippage_percent' => 0,
        ];

        $this->assertSame('blocked', $service->readiness($profile, $base + [
            'strategy_version_overrides' => [(string) $binding->id => $draft->id],
        ])['status']);
        $this->assertContains('counterfactual_version_not_published', $service->readiness($profile, $base + [
            'strategy_version_overrides' => [(string) $binding->id => $draft->id],
        ])['limitations']);

        $wrong = $service->readiness($profile, $base + [
            'strategy_version_overrides' => [(string) $binding->id => $wrongType->id],
        ]);
        $this->assertSame('blocked', $wrong['status']);
        $this->assertContains('counterfactual_version_wrong_lineage', $wrong['limitations']);

        $other = User::factory()->create();
        $foreignArtifact = app(ReusableArtifactLifecycleService::class)->createDraft($other, ArtifactType::STRATEGY, 'foreign', 'Foreign', $this->strategyEnvelope(80));
        $foreignVersion = app(ReusableArtifactLifecycleService::class)->publish($foreignArtifact, $other);
        $inaccessible = $service->readiness($profile, $base + [
            'strategy_version_overrides' => [(string) $binding->id => $foreignVersion->id],
        ]);
        $this->assertSame('blocked', $inaccessible['status']);
        $this->assertContains('counterfactual_version_inaccessible', $inaccessible['limitations']);

        $unknown = $service->readiness($profile, $base + [
            'strategy_version_overrides' => ['999999' => $v2->id],
        ]);
        $this->assertSame('blocked', $unknown['status']);
        $this->assertContains('counterfactual_binding_not_in_historical_world', $unknown['limitations']);

        $newMode = $service->readiness($profile, [
            'starting_mode' => 'new_simulated', 'period_start' => '2026-01-02', 'period_end' => '2026-01-02',
            'starting_cash' => 10000, 'price_method' => 'next_open', 'adverse_slippage_percent' => 0,
            'strategy_version_overrides' => [(string) $binding->id => $v2->id],
        ]);
        $this->assertSame('blocked', $newMode['status']);
        $this->assertContains('counterfactual_overrides_require_historical_branch', $newMode['limitations']);
    }

    /** @return array{0:User,1:\App\Models\PortfolioProfile,2:ArtifactBinding,3:ReusableArtifactVersion,4:ReusableArtifactVersion,5:ReusableArtifactVersion} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'COUNTER', 'exchange' => 'NSE', 'name' => 'Counterfactual']);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-02', 'open_price' => 100, 'high_price' => 105,
            'low_price' => 95, 'close_price' => 102, 'data_source' => 'test',
        ]);
        app(\App\Services\CashManagementService::class)->deposit($profile, 10000, 'Counterfactual opening cash', $user, '2026-01-01');

        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $v1 = $lifecycle->publish($lifecycle->createDraft($user, ArtifactType::STRATEGY, 'counter-strategy', 'Counter Strategy', $this->strategyEnvelope(50)), $user);
        $v2 = $lifecycle->publish($lifecycle->createNextDraft($v1, $user, '2.0.0', $this->strategyEnvelope(75)), $user);
        $v3 = $lifecycle->publish($lifecycle->createNextDraft($v2, $user, '3.0.0', $this->strategyEnvelope(90)), $user);
        $binding = app(\App\Services\Artifacts\ArtifactBindingService::class)->bind($profile, $v1, $user, ['allocation_pct' => 100], true);
        DB::table('portfolio_artifact_binding_revisions')->where('id', $binding->active_revision_id)->update([
            'activated_at' => '2026-01-01 09:00:00', 'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-01-01 09:00:00',
        ]);
        $strategy = TradingStrategy::query()->where('profile_id', $profile->id)->where('reusable_artifact_id', $v1->artifact_id)->sole();
        DB::table('portfolio_tos_strategies')->where('id', $strategy->id)->update(['created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-01-01 09:00:00']);
        $revision = ArtifactBindingRevision::query()->create([
            'binding_id' => $binding->id, 'revision_number' => 2, 'artifact_version_id' => $v3->id,
            'settings_json' => ['allocation_pct' => 100], 'binding_status' => ArtifactBinding::STATUS_ENABLED,
            'usability_state' => ArtifactBinding::USABLE, 'action' => 'upgrade', 'activated_by_user_id' => $user->id,
            'activated_at' => '2026-02-01 09:00:00',
        ]);
        $binding->forceFill(['active_revision_id' => $revision->id])->save();

        return [$user, $profile, $binding->fresh('activeRevision'), $v1, $v2, $v3];
    }

    /** @return array<string,mixed> */
    private function strategyEnvelope(int $threshold): array
    {
        return [
            'schema_version' => '1.0', 'artifact_type' => ArtifactType::STRATEGY, 'slug' => 'counter-strategy',
            'name' => 'Counter Strategy', 'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'scoring_model' => [['key' => 'momentum_score', 'enabled' => true, 'weight' => 100, 'parameters' => ['rsi_period' => 14]]],
                'eligibility_sources' => [], 'thresholds' => ['open_position' => $threshold],
            ],
        ];
    }
}
