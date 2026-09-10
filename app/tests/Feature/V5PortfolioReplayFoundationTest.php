<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBindingRevision;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\PortfolioReplayRun;
use App\Models\PortfolioReplayCheckpoint;
use App\Models\User;
use App\Services\Simulation\PortfolioReplayProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V5PortfolioReplayFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_scenario_pins_world_and_cannot_be_modified_after_queueing(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $artifact = ReusableArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(), 'owner_user_id' => $user->id,
            'artifact_type' => 'strategy', 'slug' => 'replay-strategy', 'name' => 'Replay Strategy', 'origin' => 'authored',
        ]);
        $version = ReusableArtifactVersion::query()->create([
            'artifact_id' => $artifact->id, 'semver' => '1.0.0', 'status' => 'published',
            'content_json' => ['rules' => []], 'definition_hash' => hash('sha256', 'replay'),
            'created_by_user_id' => $user->id, 'published_at' => now(),
        ]);
        $binding = ArtifactBinding::query()->create([
            'binding_uuid' => (string) Str::uuid(), 'profile_id' => $profile->id,
            'artifact_id' => $artifact->id, 'status' => 'enabled', 'usability_state' => 'usable',
        ]);
        $revision = ArtifactBindingRevision::query()->create([
            'binding_id' => $binding->id, 'revision_number' => 1, 'artifact_version_id' => $version->id,
            'binding_status' => 'enabled', 'usability_state' => 'usable', 'action' => 'created',
            'activated_by_user_id' => $user->id, 'activated_at' => now(),
        ]);
        $binding->forceFill(['active_revision_id' => $revision->id])->save();

        $payload = [
            'starting_mode' => 'new_simulated', 'period_start' => '2026-01-02', 'period_end' => '2026-01-30',
            'starting_cash' => 100000, 'price_method' => 'next_open', 'adverse_slippage_percent' => 0.5,
        ];
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/replays/readiness', $payload)->assertOk()->assertJsonPath('data.status', 'ready');
        $created = $this->postJson('/api/replays', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.pinned_world.binding_revisions.0.artifact_version_id', $version->id);
        $id = $created->json('data.id');
        $this->putJson('/api/replays/'.$id, ['starting_cash' => 1])->assertMethodNotAllowed();
        $run = PortfolioReplayRun::query()->findOrFail($id);
        $this->assertStringStartsWith('settings-sha256:', $run->pinned_world['charge_model']['version']);
        $this->assertNotEmpty($run->pinned_world['charge_model']['components']);
        $this->assertSame('india_equity', $run->pinned_world['calendar']['market']);
        $this->assertSame('daily_eod', $run->pinned_world['calendar']['resolution']);
        $slice = app(PortfolioReplayProcessor::class)->process($run, 2);
        $this->assertSame('running', $slice['status']);
        $this->assertSame(2, $slice['processed_sessions']);
        $this->assertSame('2026-01-05', $slice['checkpoint_date']);
        app(PortfolioReplayProcessor::class)->process($run->fresh(), 1);
        $this->assertSame(3, PortfolioReplayCheckpoint::query()->where('replay_run_id', $id)->count());
        $checkpoint = PortfolioReplayCheckpoint::query()->where('replay_run_id', $id)->latest('id')->firstOrFail();
        $this->assertNotEmpty($checkpoint->market_evidence['sha256']);
        $this->assertContains('portfolio_economic_engine_pending_integration', $checkpoint->limitations);
        $this->postJson('/api/replays/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, app(PortfolioReplayProcessor::class)->process($run->fresh(), 5)['processed_sessions']);
        $this->deleteJson('/api/replays/'.$id)->assertOk();
        $this->getJson('/api/replays/'.$id)->assertNotFound();
        $this->assertDatabaseHas('portfolio_replay_run_tombstones', [
            'run_uuid' => $created->json('data.run_uuid'), 'final_status' => 'cancelled',
            'deleted_by_user_id' => $user->id,
        ]);
    }

    public function test_missing_strategy_world_blocks_instead_of_silently_shortening(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/replays', [
                'starting_mode' => 'new_simulated', 'period_start' => '2026-01-02', 'period_end' => '2026-01-30',
                'starting_cash' => 100000, 'price_method' => 'next_open',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.readiness.0', 'no_enabled_strategy_artifact_bindings');
    }
}
