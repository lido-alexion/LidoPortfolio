<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactRuntimeBindingResolver;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use App\Services\Screener\ScreenerRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TradingArtifactRolloutAssuranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_screener_rolls_out_and_rolls_back_without_rewriting_history(): void
    {
        [$owner, $profile, $screener] = $this->fixture();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bindings = app(ArtifactBindingService::class);
        $resolver = app(ArtifactRuntimeBindingResolver::class);
        $runs = app(ScreenerRunService::class);

        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'rollout', 'Rollout', $this->envelope(0, 'gt')),
            $owner,
            [['kind' => 'uses_indicator', 'indicator_id' => 'sma', 'indicator_version' => '1.0.0']],
        );
        $binding = $bindings->bind($profile, $v1, $owner, ['schedule' => 'daily'], true);
        $runtimeScreener = Screener::query()
            ->where('profile_id', $profile->id)
            ->where('reusable_artifact_id', $v1->artifact_id)
            ->sole();

        $selection = $resolver->forScreener($runtimeScreener->fresh());
        $this->assertSame($v1->id, $selection->artifactVersion->id);
        $runV1 = $runs->runToCompletion($runtimeScreener->fresh());
        $this->assertSame($v1->id, $runV1->reusable_artifact_version_id);
        $this->assertSame($binding->active_revision_id, $runV1->artifact_binding_revision_id);
        $this->assertTrue($runV1->hits()->exists());

        $v2 = $lifecycle->publish(
            $lifecycle->createNextDraft(
                $v1,
                $owner,
                '2.0.0',
                $this->envelope(1000, 'gt'),
            ),
            $owner,
        );
        $upgraded = $bindings->upgrade($binding, $v2, $owner, 0, null, 'Roll out v2');
        $this->assertSame($v2->id, $upgraded->activeRevision->artifact_version_id);

        $runV2 = $runs->runToCompletion($runtimeScreener->fresh());
        $this->assertSame($v2->id, $runV2->reusable_artifact_version_id);
        $this->assertSame($upgraded->active_revision_id, $runV2->artifact_binding_revision_id);
        $this->assertFalse($runV2->hits()->exists());

        $this->assertSame($v1->id, $runV1->fresh()->reusable_artifact_version_id);
        $this->assertNotSame($runV1->artifact_binding_revision_id, $runV2->artifact_binding_revision_id);

        $rolledBack = $bindings->upgrade($upgraded, $v1, $owner, 1, null, 'Roll back to v1');
        $this->assertSame($v1->id, $rolledBack->activeRevision->artifact_version_id);
        $this->assertSame('upgrade', $rolledBack->activeRevision->action);

        $runAfterRollback = $runs->runToCompletion($runtimeScreener->fresh());
        $this->assertSame($v1->id, $runAfterRollback->reusable_artifact_version_id);
        $this->assertSame(3, $rolledBack->revisions()->count());

        $sameRollback = $bindings->upgrade($rolledBack, $v1, $owner, 2, null, 'Rollback remains v1');
        $this->assertSame($v1->id, $sameRollback->activeRevision->artifact_version_id);
        $this->assertSame(4, $sameRollback->revisions()->count());
    }

    public function test_invalid_version_and_missing_dependency_fail_closed_without_binding_change(): void
    {
        [$owner, $profile] = $this->fixture();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bindings = app(ArtifactBindingService::class);
        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'guarded', 'Guarded', $this->envelope(0, 'gt')),
            $owner,
        );
        $binding = $bindings->bind($profile, $v1, $owner, [], true);

        $invalid = $lifecycle->createNextDraft($v1, $owner, '2.0.0', $this->envelope(0, 'not-an-operator'));
        $this->expectException(InvalidArgumentException::class);
        try {
            $lifecycle->publish($invalid, $owner);
        } finally {
            $this->assertSame($v1->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
        }
    }

    public function test_missing_dependency_is_rejected_before_any_published_version_or_binding_change(): void
    {
        [$owner, $profile] = $this->fixture();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bindings = app(ArtifactBindingService::class);
        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'dependency-guard', 'Dependency Guard', $this->envelope(0, 'gt')),
            $owner,
        );
        $binding = $bindings->bind($profile, $v1, $owner, [], true);
        $draft = $lifecycle->createNextDraft($v1, $owner, '2.0.0');

        $this->expectException(InvalidArgumentException::class);
        try {
            $lifecycle->publish($draft, $owner, [['kind' => 'uses_screener', 'artifact_version_id' => 999999]]);
        } finally {
            $this->assertSame($v1->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
            $this->assertSame(1, $draft->fresh()->artifact->versions()->where('status', 'published')->count());
        }
    }

    public function test_archiving_preserves_published_version_and_historical_run_evidence(): void
    {
        [$owner, $profile, $screener] = $this->fixture();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bindings = app(ArtifactBindingService::class);
        $version = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'archived', 'Archived', $this->envelope(0, 'gt')),
            $owner,
        );
        $binding = $bindings->bind($profile, $version, $owner, [], true);
        $runtimeScreener = Screener::query()
            ->where('profile_id', $profile->id)
            ->where('reusable_artifact_id', $version->artifact_id)
            ->sole();
        $run = app(ScreenerRunService::class)->runToCompletion($runtimeScreener);

        $archived = $lifecycle->archive($version->artifact, $owner);

        $this->assertNotNull($archived->archived_at);
        $this->assertSame('published', $version->fresh()->status);
        $this->assertSame($version->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
        $this->assertSame($version->id, $run->fresh()->reusable_artifact_version_id);
        $this->assertInstanceOf(ScreenerRun::class, $run->fresh()->load('reusableArtifactVersion'));
    }

    /** @return array{0:User,1:PortfolioProfile,2:Screener} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $stock = Stock::query()->create([
            'symbol' => 'ARTIFACT',
            'exchange' => 'NSE',
            'name' => 'Artifact Fixture',
            'is_active' => true,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 100,
            'invested_amount' => 100,
        ]);
        for ($day = 10; $day >= 0; $day--) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($day)->toDateString(),
                'open_price' => 100,
                'high_price' => 101,
                'low_price' => 99,
                'close_price' => 100,
                'adjusted_close_price' => 100,
                'volume' => 10000,
                'data_source' => 'test',
            ]);
        }
        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Artifact Fixture',
            'slug' => 'artifact_fixture',
            'artifact_version' => 1,
            'artifact_status' => 'active',
            'scope' => 'holdings',
            'definition_json' => $this->envelope(0, 'gt')['definition'],
            'is_enabled' => true,
            'telegram_enabled' => false,
        ]);

        return [$owner, $profile, $screener];
    }

    /** @return array<string, mixed> */
    private function envelope(int|float $constant, string $operator): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'screener',
            'slug' => 'artifact-fixture',
            'name' => 'Artifact Fixture',
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'sma', 'params' => ['period' => 5]],
                    'operator' => $operator,
                    'right' => ['type' => 'constant', 'value' => $constant],
                ],
            ],
        ];
    }
}
