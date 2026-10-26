<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\ScreenerBacktestDay;
use App\Models\ScreenerRun;
use App\Models\ScreenerVersion;
use App\Exceptions\DomainException;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\Artifacts\LegacyArtifactBackfillService;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\EquityUniverseService;
use App\Services\IndexConstituentService;
use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerRunService;
use App\Services\Screener\ScreenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

/** GH #286: a version ID cannot stand in for actually executing that version. */
class ScreenerRunSnapshotTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 12:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_continuation_uses_pinned_rules_and_selector_after_a_semantic_edit(): void
    {
        [$user, $profile, $watchlist, $stocks, $screener] = $this->fixture();
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $this->assertFalse($first['completed']);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $versionId = $run->screener_version_id;
        $snapshot = $run->stats_json['universe_snapshot'];
        $lastStock = $stocks->last();
        $alternate = Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'Changed selector']);

        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/screeners/'.$screener->id, [
                'name' => 'Run snapshot', 'scope' => 'watchlist', 'watchlist_id' => $alternate->id,
                'definition_json' => $this->definition(200),
            ])->assertOk();
        $newVersion = ScreenerVersion::query()->where('screener_id', $screener->id)->where('version', 2)->sole();
        $this->assertNotSame($versionId, $newVersion->id);

        $response = $this->postJson('/api/screener-runs/'.$run->id.'/continue')->assertOk()
            ->assertJsonPath('completed', true)->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.screener_version_id', $versionId)
            ->assertJsonMissingPath('data.stats.universe_snapshot');
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE + 1, $response->json('data.stats.matched'));
        $this->assertTrue($run->hits()->where('stock_id', $lastStock->id)->exists());
        $this->assertSame($snapshot, $run->fresh()->stats_json['universe_snapshot']);
        $this->assertSame(1, ScreenerBacktestDay::query()->where('screener_version_id', $versionId)->count());
        $this->assertSame(0, ScreenerBacktestDay::query()->where('screener_version_id', $newVersion->id)->count());
        $this->assertSame(200.0, (float) $newVersion->definition_json['root']['right']['value']);
        $this->assertSame($alternate->id, $screener->fresh()->watchlist_id);
    }

    public function test_membership_change_does_not_shift_the_snapshot_cursor_or_add_late_members(): void
    {
        [, $profile, $watchlist, $stocks, $screener] = $this->fixture();
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $original = $run->stats_json['universe_snapshot'];
        $lastStock = $stocks->last();
        WatchlistItem::query()->where('watchlist_id', $watchlist->id)->where('stock_id', $stocks->first()->id)->delete();
        $late = Stock::query()->create(['symbol' => 'ACPTLATE', 'name' => 'Late member', 'exchange' => 'NSE', 'is_active' => true]);
        StockPrice::query()->create($this->bar($late->id));
        WatchlistItem::query()->create(['profile_id' => $profile->id, 'watchlist_id' => $watchlist->id, 'stock_id' => $late->id]);

        $continued = $service->continue($run);
        $this->assertTrue($continued['completed']);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE + 1, $continued['run']['stats']['total']);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE + 1, $continued['run']['stats']['matched']);
        $this->assertTrue($run->hits()->where('stock_id', $lastStock->id)->exists());
        $this->assertFalse($run->hits()->where('stock_id', $late->id)->exists());
        $this->assertSame($original, $run->fresh()->stats_json['universe_snapshot']);
    }

    public function test_new_run_excludes_already_ineligible_members_without_changing_the_public_resolver(): void
    {
        [, $profile, $watchlist, $stocks, $screener] = $this->fixture(1);
        $eligible = [$stocks->first()->id];
        $ineligible = [];
        foreach ([['ACPTACTIVE', true, false], ['ACPTINACTIVE', false, false], ['ACPTADMIN', true, true]] as [$symbol, $active, $admin]) {
            $stock = Stock::query()->create(['symbol' => $symbol, 'name' => $symbol, 'exchange' => 'NSE', 'is_active' => $active]);
            $stock->forceFill(['admin_deactivated' => $admin])->save();
            StockPrice::query()->create($this->bar($stock->id));
            WatchlistItem::query()->create(['profile_id' => $profile->id, 'watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);
            if ($active && ! $admin) {
                $eligible[] = $stock->id;
            } else {
                $ineligible[] = $stock->id;
            }
        }
        $service = app(ScreenerRunService::class);
        [$resolved] = $service->resolveStockIds($screener);
        $this->assertEqualsCanonicalizing([...$eligible, ...$ineligible], $resolved);
        $expectedOrder = array_values(array_filter($resolved, fn ($id) => in_array($id, $eligible, true)));

        $result = $service->start($screener);
        $run = ScreenerRun::query()->findOrFail($result['run']['id']);
        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $result['run']['stats']['total']);
        $this->assertSame(2, $result['run']['stats']['matched']);
        $this->assertSame($expectedOrder, $run->stats_json['universe_snapshot']['stock_ids']);
        $this->assertEqualsCanonicalizing($eligible, $run->hits()->pluck('stock_id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse($run->hits()->whereIn('stock_id', $ineligible)->exists());
        [$after] = $service->resolveStockIds($screener);
        $this->assertSame($resolved, $after);

        Stock::query()->whereIn('id', $eligible)->update(['is_active' => false]);
        $empty = $service->start($screener);
        $emptyRun = ScreenerRun::query()->findOrFail($empty['run']['id']);
        $this->assertTrue($empty['completed']);
        $this->assertSame(0, $empty['run']['stats']['total']);
        $this->assertSame([], $emptyRun->stats_json['universe_snapshot']['stock_ids']);
        $this->assertSame($expectedOrder, $run->fresh()->stats_json['universe_snapshot']['stock_ids']);
        $this->assertSame(2, $run->hits()->count());
    }

    public function test_index_membership_is_resolved_once_for_the_whole_run(): void
    {
        [, $profile, , $stocks] = $this->fixture();
        $screenerData = app(ScreenerService::class)->create($profile, [
            'name' => 'Index snapshot', 'scope' => 'index', 'index_symbol' => 'NIFTY50',
            'definition_json' => $this->definition(1), 'telegram_enabled' => false,
        ]);
        $provider = Mockery::mock(IndexConstituentService::class);
        $provider->shouldReceive('constituentsForSymbol')->once()->with('NIFTY50', false)
            ->andReturn($stocks->map(fn ($s) => ['stock_id' => $s->id])->all());
        $this->app->instance(IndexConstituentService::class, $provider);
        $service = app(ScreenerRunService::class);
        $first = $service->start(Screener::query()->findOrFail($screenerData['id']));
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);

        $continued = $service->continue($run);
        $this->assertTrue($continued['completed']);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE + 1, $continued['run']['stats']['matched']);
        $this->assertSame($stocks->pluck('id')->all(), $run->fresh()->stats_json['universe_snapshot']['stock_ids']);
    }

    public function test_pre_snapshot_legacy_continuation_fails_honestly_and_idempotently(): void
    {
        [, , , , $screener] = $this->fixture(1);
        $service = app(ScreenerRunService::class);
        $complete = $service->start($screener);
        $completedRun = ScreenerRun::query()->findOrFail($complete['run']['id']);
        $historical = $completedRun->getAttributes();
        $oldHits = $completedRun->hits()->get()->map->getAttributes()->all();
        foreach ([0, 1] as $cursor) {
            $legacy = ScreenerRun::query()->create([
                'screener_id' => $screener->id, 'screener_version_id' => $completedRun->screener_version_id,
                'status' => 'running', 'triggered_by' => 'manual', 'started_at' => now(),
                'stats_json' => ['cursor' => $cursor, 'scanned' => $cursor, 'matched' => $cursor],
            ]);
            $result = $service->continue($legacy);
            $this->assertFalse($result['completed']);
            $this->assertSame('failed', $legacy->fresh()->status);
            $this->assertStringContainsString('captured universe evidence', $legacy->fresh()->error_message);
            $this->assertSame($cursor, $legacy->fresh()->stats_json['cursor']);
            $this->assertArrayNotHasKey('universe_snapshot', $legacy->fresh()->stats_json);
            $failed = $legacy->fresh()->getAttributes();
            $service->continue($legacy->fresh());
            $this->assertSame($failed, $legacy->fresh()->getAttributes());
        }
        $service->continue($completedRun);
        $this->assertSame($historical, $completedRun->fresh()->getAttributes());
        $this->assertSame($oldHits, $completedRun->hits()->get()->map->getAttributes()->all());
    }

    public function test_missing_or_foreign_version_pin_cannot_fall_back_to_current_semantics(): void
    {
        [, $profile, , , $screener] = $this->fixture(1);
        $service = app(ScreenerRunService::class);
        $completed = $service->start($screener);
        $base = ScreenerRun::query()->findOrFail($completed['run']['id']);
        $other = app(ScreenerService::class)->create($profile, [
            'name' => 'Different lineage', 'scope' => 'holdings', 'definition_json' => $this->definition(1),
        ]);
        $foreignPin = ScreenerVersion::query()->where('screener_id', $other['id'])->value('id');
        foreach ([null, $foreignPin] as $pin) {
            $run = ScreenerRun::query()->create([
                'screener_id' => $screener->id, 'screener_version_id' => $pin, 'status' => 'running',
                'triggered_by' => 'manual', 'started_at' => now(), 'stats_json' => $base->stats_json,
            ]);
            $service->continue($run);
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertStringContainsString('immutable version evidence', $run->fresh()->error_message);
            $this->assertSame(0, $run->hits()->count());
        }
    }

    public function test_empty_snapshotted_universe_completes_without_resolving_current_membership(): void
    {
        [, , $watchlist, , $screener] = $this->fixture(0);
        $result = app(ScreenerRunService::class)->start($screener);
        $this->assertTrue($result['completed']);
        $this->assertSame(0, $result['run']['stats']['total']);
        $run = ScreenerRun::query()->findOrFail($result['run']['id']);
        $this->assertSame([], $run->stats_json['universe_snapshot']['stock_ids']);
        $this->assertSame($watchlist->id, $run->stats_json['universe_snapshot']['watchlist_id']);
    }

    public function test_invalid_snapshot_and_cursor_fail_before_any_stock_is_evaluated(): void
    {
        [, , , $stocks, $screener] = $this->fixture(1);
        $service = app(ScreenerRunService::class);
        $completed = $service->start($screener);
        $base = ScreenerRun::query()->findOrFail($completed['run']['id']);
        $stockId = $stocks->first()->id;
        foreach ([
            'duplicate' => [$stockId, $stockId],
            'non_integer' => [(string) $stockId],
            'negative' => [-1],
            'too_large' => range(1, 100001),
            'cursor' => [$stockId],
        ] as $fault => $ids) {
            $stats = $base->stats_json;
            $stats['universe_snapshot']['stock_ids'] = $ids;
            $stats['cursor'] = $fault === 'cursor' ? 2 : 0;
            $stats['scanned'] = $stats['matched'] = 0;
            $run = ScreenerRun::query()->create([
                'screener_id' => $screener->id, 'screener_version_id' => $base->screener_version_id,
                'status' => 'running', 'triggered_by' => 'manual', 'started_at' => now(), 'stats_json' => $stats,
            ]);
            $service->continue($run);
            $this->assertSame('failed', $run->fresh()->status, $fault);
            $this->assertSame(0, $run->hits()->count(), $fault);
            $this->assertSame(0, $run->fresh()->stats_json['scanned'], $fault);
        }
    }

    public function test_new_data_quality_block_fails_closed_without_rewriting_the_universe(): void
    {
        [, , , $stocks, $screener] = $this->fixture();
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $snapshot = $run->stats_json['universe_snapshot'];
        $this->createPendingExchangeIssue($stocks->last());

        $result = $service->continue($run);
        $this->assertFalse($result['completed']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('Market-data quality changed', $run->fresh()->error_message);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE, $run->hits()->count());
        $this->assertSame($snapshot, $run->fresh()->stats_json['universe_snapshot']);
    }


    public function test_mapped_continuation_keeps_consistent_pins_after_a_binding_upgrade(): void
    {
        [$user, $profile, , $stocks, $screener] = $this->fixture();
        $backfilled = app(LegacyArtifactBackfillService::class)->backfill($profile);
        $this->assertSame(0, $backfilled['failed']);
        $screener = $screener->fresh('reusableArtifact');
        $binding = $screener->reusableArtifact->bindings()->where('profile_id', $profile->id)->sole();
        $artifact = $binding->activeRevision->artifactVersion;
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $pin = ScreenerVersion::query()->findOrFail($run->screener_version_id);
        $pinBefore = $pin->getAttributes();
        $this->assertSame(DefinitionHasher::hash($artifact->content_json['definition']), DefinitionHasher::hash($pin->definition_json));

        $nextContent = $artifact->content_json;
        $nextContent['definition'] = $this->definition(200);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $next = $lifecycle->publish($lifecycle->createNextDraft($artifact, $user, '1.1.0', $nextContent), $user);
        $alternate = Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'New binding selector']);
        app(ArtifactBindingService::class)->upgrade($binding, $next, $user, (int) $binding->lock_version, [
            'scope' => 'watchlist', 'watchlist_id' => $alternate->id,
        ]);

        $continued = $service->continue($run);
        $this->assertTrue($continued['completed']);
        $this->assertSame($artifact->id, $run->fresh()->reusable_artifact_version_id);
        $this->assertSame($binding->active_revision_id, $run->fresh()->artifact_binding_revision_id);
        $this->assertSame($pin->id, $run->fresh()->screener_version_id);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE + 1, $run->fresh()->stats_json['matched']);
        $this->assertTrue($run->hits()->where('stock_id', $stocks->last()->id)->exists());
        $this->assertSame($pinBefore, $pin->fresh()->getAttributes());
        $this->assertSame($alternate->id, $screener->fresh()->watchlist_id);

        $later = $service->start($screener->fresh());
        $laterRun = ScreenerRun::query()->findOrFail($later['run']['id']);
        $laterPin = ScreenerVersion::query()->findOrFail($laterRun->screener_version_id);
        $this->assertSame($next->id, $laterRun->reusable_artifact_version_id);
        $this->assertSame(DefinitionHasher::hash($nextContent['definition']), DefinitionHasher::hash($laterPin->definition_json));
        $this->assertSame($alternate->id, $laterPin->watchlist_id);
        $this->assertSame(0, $later['run']['stats']['total']);
        $this->assertSame($pinBefore, $pin->fresh()->getAttributes());
    }

    public function test_publishable_free_form_universe_preserves_projection_fallback_through_continuation(): void
    {
        [$user, $profile, , $stocks, $screener] = $this->fixture();
        $this->assertSame(0, app(LegacyArtifactBackfillService::class)->backfill($profile)['failed']);
        $source = $screener->fresh('reusableArtifact')->reusableArtifact->bindings()
            ->where('profile_id', $profile->id)->sole()->activeRevision->artifactVersion;
        $content = $source->content_json;
        $content['slug'] = 'legacy_universe_fallback';
        $content['name'] = 'Legacy universe fallback';
        $content['metadata']['universe'] = 'legacy free-form equities label';
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $artifact = $lifecycle->publish($lifecycle->createDraft(
            $user, $source->artifact->artifact_type, $content['slug'], $content['name'], $content,
        ), $user);
        $binding = app(ArtifactBindingService::class)->bind($profile, $artifact, $user, [], true);
        $mapped = Screener::query()->where('profile_id', $profile->id)->where('reusable_artifact_id', $artifact->artifact_id)->sole();
        $this->assertSame('all_equities', $mapped->scope);
        $provider = Mockery::mock(EquityUniverseService::class);
        $provider->shouldReceive('universeStockQuery')->once()->andReturn(Stock::query()->whereIn('id', $stocks->pluck('id')->all()));
        $this->app->instance(EquityUniverseService::class, $provider);
        $service = app(ScreenerRunService::class);

        $first = $service->start($mapped);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $pin = ScreenerVersion::query()->findOrFail($run->screener_version_id);
        $this->assertFalse($first['completed']);
        $this->assertSame('all_equities', $pin->scope);
        $this->assertSame('all_equities', $run->stats_json['universe_snapshot']['scope']);
        $this->assertSame(DefinitionHasher::hash($content['definition']), DefinitionHasher::hash($pin->definition_json));
        $this->assertSame($artifact->id, $run->reusable_artifact_version_id);
        $this->assertSame($binding->active_revision_id, $run->artifact_binding_revision_id);

        $continued = $service->continue($run);
        $this->assertTrue($continued['completed']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE + 1, $continued['run']['stats']['matched']);
        $this->assertTrue($run->hits()->where('stock_id', $stocks->last()->id)->exists());
        $this->assertSame($pin->id, $run->fresh()->screener_version_id);
    }

    public function test_mapped_runtime_captures_the_artifact_not_a_drifted_mutable_definition(): void
    {
        [, $profile, , , $screener] = $this->fixture(1);
        $this->assertSame(0, app(LegacyArtifactBackfillService::class)->backfill($profile)['failed']);
        $screener = $screener->fresh('reusableArtifact');
        $binding = $screener->reusableArtifact->bindings()->where('profile_id', $profile->id)->sole();
        $artifact = $binding->activeRevision->artifactVersion;
        $screener->forceFill(['definition_json' => $this->definition(200)])->save();
        $ordinal = $screener->artifact_version;
        $projectionHash = $screener->definition_hash;
        $result = app(ScreenerRunService::class)->start($screener);
        $run = ScreenerRun::query()->findOrFail($result['run']['id']);
        $pin = ScreenerVersion::query()->findOrFail($run->screener_version_id);

        $this->assertSame(1, $result['run']['stats']['matched']);
        $this->assertSame($artifact->id, $run->reusable_artifact_version_id);
        $this->assertSame(DefinitionHasher::hash($artifact->content_json['definition']), DefinitionHasher::hash($pin->definition_json));
        $this->assertSame(200.0, (float) $screener->fresh()->definition_json['root']['right']['value']);
        $this->assertSame($ordinal, $screener->fresh()->artifact_version);
        $this->assertSame($projectionHash, $screener->fresh()->definition_hash);
    }

    public function test_conflicting_mapped_semantic_evidence_is_not_rewritten_or_executed(): void
    {
        [, $profile, $watchlist, , $screener] = $this->fixture(1);
        app(LegacyArtifactBackfillService::class)->backfill($profile);
        $screener = $screener->fresh();
        // Simulate unresolved legacy/corrupted evidence; runtime must not rewrite it.
        $conflict = ScreenerVersion::query()->where('screener_id', $screener->id)
            ->where('version', $screener->artifact_version)->sole();
        $conflict->forceFill(['definition_json' => $this->definition(200), 'definition_hash' => 'unresolved-legacy-proof'])->save();
        $before = $conflict->fresh()->getAttributes();
        $versionsBefore = ScreenerVersion::query()->count();
        try {
            app(ScreenerRunService::class)->start($screener);
            $this->fail('Conflicting semantic/artifact evidence must fail closed.');
        } catch (DomainException $error) {
            $this->assertSame('SCREENER_RUNTIME_SEMANTICS_CONFLICT', $error->errorCode());
        }
        $this->assertSame(0, ScreenerRun::query()->count());
        $this->assertSame($versionsBefore, ScreenerVersion::query()->count());
        $this->assertSame($before, $conflict->fresh()->getAttributes());

        // A later explicit owner binding revision may adopt known current semantics
        // in a NEW version; it must not rewrite or silently relabel the old evidence.
        $screener = $screener->fresh('reusableArtifact');
        $binding = $screener->reusableArtifact->bindings()->where('profile_id', $profile->id)->sole();
        app(ArtifactBindingService::class)->upgrade(
            $binding, $binding->activeRevision->artifactVersion, $profile->user, (int) $binding->lock_version,
        );
        $adopted = app(ScreenerRunService::class)->start($screener->fresh());
        $this->assertTrue($adopted['completed']);
        $this->assertSame(1, $adopted['run']['stats']['matched']);
        $this->assertNotSame($conflict->id, $adopted['run']['screener_version_id']);
        $this->assertSame($before, $conflict->fresh()->getAttributes());
    }

    public function test_mapped_run_does_not_fill_a_missing_historical_version_gap(): void
    {
        [, $profile, $watchlist, , $screener] = $this->fixture(1);
        app(LegacyArtifactBackfillService::class)->backfill($profile);
        $screener = $screener->fresh();
        $missingOrdinal = ((int) $screener->artifact_version) + 1;
        $screener->forceFill(['artifact_version' => $missingOrdinal])->save(); // Pre-existing pointer/gap fixture only.
        ScreenerVersion::query()->create([
            'screener_id' => $screener->id, 'version' => $missingOrdinal + 1,
            'definition_json' => $this->definition(1), 'scope' => 'watchlist',
            'watchlist_id' => $watchlist->id, 'definition_hash' => 'later-version-evidence',
        ]);
        try {
            app(ScreenerRunService::class)->start($screener);
            $this->fail('A missing historical slot must not be inferred from a new run.');
        } catch (DomainException $error) {
            $this->assertSame('SCREENER_RUNTIME_SEMANTICS_CONFLICT', $error->errorCode());
        }
        $this->assertFalse(ScreenerVersion::query()->where('screener_id', $screener->id)->where('version', $missingOrdinal)->exists());
        $this->assertSame(0, ScreenerRun::query()->count());
    }

    public function test_security_deactivation_does_not_make_a_frozen_member_an_eligible_candidate(): void
    {
        [, , , $stocks, $screener] = $this->fixture();
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $stocks->last()->forceFill(['is_active' => false])->save();

        $result = $service->continue($run);
        $this->assertFalse($result['completed']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('Security eligibility changed', $run->fresh()->error_message);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE, $run->hits()->count());
    }


    public function test_admin_deactivation_blocks_a_still_active_snapshotted_security(): void
    {
        [, , , $stocks, $screener] = $this->fixture();
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $run = ScreenerRun::query()->findOrFail($first['run']['id']);
        $snapshot = $run->stats_json['universe_snapshot'];
        $stock = $stocks->last();
        $stock->forceFill(['is_active' => true, 'admin_deactivated' => true])->save();
        $this->assertTrue((bool) $stock->fresh()->is_active);
        $this->assertTrue($stock->fresh()->isAdminDeactivated());

        $result = $service->continue($run);
        $this->assertFalse($result['completed']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('Security eligibility changed', $run->fresh()->error_message);
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE, $run->hits()->count());
        $this->assertSame(ScreenerCatalog::CHUNK_SIZE, $run->fresh()->stats_json['cursor']);
        $this->assertSame($snapshot, $run->fresh()->stats_json['universe_snapshot']);
    }

    public function test_selector_only_binding_revision_creates_a_new_semantic_version_without_rewriting_the_old_one(): void
    {
        [$user, $profile, , , $screener] = $this->fixture(1);
        app(LegacyArtifactBackfillService::class)->backfill($profile);
        $screener = $screener->fresh('reusableArtifact');
        $binding = $screener->reusableArtifact->bindings()->where('profile_id', $profile->id)->sole();
        $service = app(ScreenerRunService::class);
        $first = $service->start($screener);
        $firstRun = ScreenerRun::query()->findOrFail($first['run']['id']);
        $oldVersion = ScreenerVersion::query()->findOrFail($firstRun->screener_version_id);
        $oldEvidence = $oldVersion->getAttributes();
        $alternate = Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'Scope revision']);
        $owner = app(ArtifactBindingService::class);
        $revised = $owner->updateSettings($binding, $user, (int) $binding->lock_version, [
            'scope' => 'watchlist', 'watchlist_id' => $alternate->id,
        ]);
        $later = $service->start($screener->fresh());
        $laterRun = ScreenerRun::query()->findOrFail($later['run']['id']);
        $newVersion = ScreenerVersion::query()->findOrFail($laterRun->screener_version_id);
        $this->assertNotSame($oldVersion->id, $newVersion->id);
        $this->assertSame($alternate->id, $newVersion->watchlist_id);
        $this->assertSame($oldVersion->definition_json, $newVersion->definition_json);
        $this->assertSame($oldEvidence, $oldVersion->fresh()->getAttributes());
        $this->assertSame($oldVersion->id, $firstRun->fresh()->screener_version_id);
        $this->assertSame(0, $later['run']['stats']['total']);

        $beforeCount = ScreenerVersion::query()->where('screener_id', $screener->id)->count();
        $owner->updateSettings($revised, $user, (int) $revised->lock_version, [
            'scope' => 'watchlist', 'watchlist_id' => $alternate->id,
            'schedule_enabled' => false, 'schedule_time' => '10:00', 'telegram_enabled' => false,
        ]);
        $this->assertSame($beforeCount, ScreenerVersion::query()->where('screener_id', $screener->id)->count());
        $this->assertSame($newVersion->version, $screener->fresh()->artifact_version);
    }

    /** Test-only DB fixture. No production account, notification, order or trade is created. */
    private function fixture(int $count = ScreenerCatalog::CHUNK_SIZE + 1): array
    {
        $user = User::factory()->create(['is_admin' => false]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = Watchlist::query()->create(['profile_id' => $profile->id, 'name' => 'Captured list']);
        $rows = [];
        for ($n = 0; $n < $count; $n++) {
            $rows[] = [
                'symbol' => 'ACPT'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'name' => 'Acceptance stock '.$n, 'exchange' => 'NSE', 'is_active' => true, 'is_benchmark' => false,
            ];
        }
        if ($rows !== []) {
            Stock::query()->insert($rows);
        }
        $stocks = Stock::query()->where('symbol', 'like', 'ACPT%')->orderBy('id')->get();
        if ($stocks->isNotEmpty()) {
            StockPrice::query()->insert($stocks->map(fn ($s) => $this->bar($s->id))->all());
            WatchlistItem::query()->insert($stocks->map(fn ($s) => [
                'profile_id' => $profile->id, 'watchlist_id' => $watchlist->id, 'stock_id' => $s->id,
            ])->all());
        }
        $data = app(ScreenerService::class)->create($profile, [
            'name' => 'Run snapshot', 'scope' => 'watchlist', 'watchlist_id' => $watchlist->id,
            'definition_json' => $this->definition(1), 'telegram_enabled' => false,
        ]);

        return [$user, $profile, $watchlist, $stocks, Screener::query()->findOrFail($data['id'])];
    }

    private function definition(float $threshold): array
    {
        return ['root' => [
            'type' => 'condition', 'left' => ['indicator' => 'close'], 'operator' => 'gt',
            'right' => ['type' => 'constant', 'value' => $threshold],
        ]];
    }

    private function bar(int $stockId): array
    {
        return [
            'stock_id' => $stockId, 'price_date' => '2026-10-23 00:00:00',
            'open_price' => 100, 'high_price' => 101, 'low_price' => 99, 'close_price' => 100,
            'adjusted_close_price' => 100, 'volume' => 10000, 'provider_source' => 'test', 'data_source' => 'test',
        ];
    }
}
