<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Artifacts\LegacyArtifactBackfillService;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\Screener\ScreenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyArtifactBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_screener_first_exact_and_idempotent(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $screener = $this->legacyScreener($profile->id);
        $strategy = $this->legacyStrategy($profile->id, $screener);
        $service = app(LegacyArtifactBackfillService::class);

        $first = $service->backfill($profile);

        $this->assertSame(['created' => 2, 'skipped' => 0, 'failed' => 0, 'failures' => []], $first);
        $screener->refresh();
        $strategy->refresh();
        $this->assertNotNull($screener->reusable_artifact_id);
        $this->assertNotNull($strategy->reusable_artifact_id);
        $this->assertSame(2, ReusableArtifact::query()->where('owner_user_id', $owner->id)->count());
        $this->assertSame(2, ArtifactBinding::query()->where('profile_id', $profile->id)->where('status', ArtifactBinding::STATUS_ENABLED)->count());
        $strategyVersion = ReusableArtifactVersion::query()
            ->where('artifact_id', $strategy->reusable_artifact_id)
            ->where('status', ReusableArtifactVersion::STATUS_PUBLISHED)
            ->sole();
        $screenerVersion = ReusableArtifactVersion::query()
            ->where('artifact_id', $screener->reusable_artifact_id)
            ->where('status', ReusableArtifactVersion::STATUS_PUBLISHED)
            ->sole();
        $this->assertTrue($strategyVersion->dependencies()->where('target_artifact_version_id', $screenerVersion->id)->exists());
        $this->assertTrue($strategyVersion->dependencies()->where('indicator_id', 'momentum_score')->where('indicator_version', '1.0.0')->exists());

        $second = $service->backfill($profile);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(0, $second['failed']);
        $this->assertSame(2, ReusableArtifact::query()->count());
    }

    public function test_invalid_legacy_row_reports_failure_without_partial_mapping_or_artifact(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $invalid = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Invalid',
            'slug' => 'invalid',
            'artifact_version' => 1,
            'artifact_status' => 'active',
            'scope' => 'all_equities',
            'definition_json' => ['root' => []],
            'is_enabled' => true,
        ]);

        $result = app(LegacyArtifactBackfillService::class)->backfill($profile);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('screener', $result['failures'][0]['type']);
        $this->assertNull($invalid->fresh()->reusable_artifact_id);
        $this->assertSame(0, ReusableArtifact::query()->count());
        $this->assertSame(0, ArtifactBinding::query()->count());
    }

    public function test_command_can_limit_backfill_to_one_profile(): void
    {
        $first = $this->defaultPortfolioFor(User::factory()->create());
        $second = $this->defaultPortfolioFor(User::factory()->create());
        $one = $this->legacyScreener($first->id);
        $two = $this->legacyScreener($second->id);

        $this->artisan('portfolio:backfill-reusable-artifacts', ['--profile' => $first->id])
            ->expectsOutput('Artifact backfill: 1 created; 0 skipped; 0 failed.')
            ->assertSuccessful();

        $this->assertNotNull($one->fresh()->reusable_artifact_id);
        $this->assertNull($two->fresh()->reusable_artifact_id);
    }

    public function test_mapped_legacy_rows_are_read_only_compatibility_projections(): void
    {
        $profile = $this->defaultPortfolioFor(User::factory()->create());
        $screener = $this->legacyScreener($profile->id);
        $strategy = $this->legacyStrategy($profile->id, $screener);
        $this->assertSame(0, app(LegacyArtifactBackfillService::class)->backfill($profile)['failed']);
        $screener = $screener->fresh();
        $strategy = $strategy->fresh();

        $formatted = app(ScreenerService::class)->format($screener);
        $this->assertTrue($formatted['compatibility_read_only']);
        $this->assertSame($screener->reusable_artifact_id, $formatted['reusable_artifact_id']);

        try {
            app(ScreenerService::class)->update($screener, ['name' => 'Bypass']);
            $this->fail('Mapped Screener updates must use the Artifact Library lifecycle.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('artifact', $error->errors());
        }

        $envelope = app(StrategyArtifactRegistry::class)->get((string) $strategy->id, $profile);
        $this->assertTrue($envelope['metadata']['compatibility_read_only']);
        $this->assertSame($strategy->reusable_artifact_id, $envelope['metadata']['reusable_artifact_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('managed by the Artifact Library');
        app(StrategyArtifactRegistry::class)->activate((string) $strategy->id, $profile);
    }

    private function legacyScreener(int $profileId): Screener
    {
        return Screener::query()->create([
            'profile_id' => $profileId,
            'name' => 'Legacy Quality',
            'slug' => 'legacy_quality',
            'artifact_version' => 2,
            'artifact_status' => 'active',
            'scope' => 'all_equities',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]],
                    'operator' => 'gte',
                    'right' => ['type' => 'constant', 'value' => 50],
                ],
            ],
            'is_enabled' => true,
            'schedule_enabled' => true,
            'schedule_time' => '09:30',
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }

    private function legacyStrategy(int $profileId, Screener $screener): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profileId,
            'name' => 'Legacy Strategy',
            'slug' => 'legacy_strategy',
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 100,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 3,
            'config_json' => [
                'scoring_model' => [[
                    'key' => 'momentum_score',
                    'enabled' => true,
                    'weight' => 100,
                    'parameters' => ['rsi_period' => 14],
                ]],
                'eligibility_sources' => [[
                    'screener_id' => $screener->id,
                    'screener_slug' => $screener->slug,
                    'enabled' => true,
                    'priority' => 1,
                ]],
            ],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh('activeVersion');
    }
}
