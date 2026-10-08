<?php

namespace Tests\Feature;

use App\Models\ExportArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class V9Data001ExportArtifactAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_can_check_or_download_a_ready_artifact(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = (string) Str::uuid();
        $path = 'exports/'.$owner->id.'/'.$token.'.csv';
        ExportArtifact::query()->create([
            'user_id' => $owner->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => $path,
            'status' => 'ready',
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($path, "snapshot_date,portfolio_value\n2026-10-08,100.00\n");

        $this->actingAs($owner)->getJson('/api/exports/'.$token)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.download_url', route('api.exports.download', $token));
        $this->actingAs($owner)->get('/api/exports/'.$token.'/download')
            ->assertOk()
            ->assertDownload('stox-portfolio-snapshots.csv');

        $this->actingAs($other)->getJson('/api/exports/'.$token)->assertNotFound();
        $this->actingAs($other)->get('/api/exports/'.$token.'/download')->assertNotFound();
    }

    public function test_expired_artifact_has_no_download_link_and_download_is_gone(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $token = (string) Str::uuid();
        $path = 'exports/'.$owner->id.'/'.$token.'.csv';
        ExportArtifact::query()->create([
            'user_id' => $owner->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => $path,
            'status' => 'ready',
            'expires_at' => now()->subSecond(),
        ]);
        Storage::disk('local')->put($path, 'expired');

        $this->actingAs($owner)->getJson('/api/exports/'.$token)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.download_url', null);
        $this->actingAs($owner)->get('/api/exports/'.$token.'/download')->assertGone();
    }

    public function test_ready_artifact_without_expiry_is_not_advertised_or_downloadable(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $token = (string) Str::uuid();
        $path = 'exports/'.$owner->id.'/'.$token.'.csv';
        ExportArtifact::query()->create([
            'user_id' => $owner->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => $path,
            'status' => 'ready',
            'expires_at' => null,
        ]);
        Storage::disk('local')->put($path, 'missing expiry');

        $this->actingAs($owner)->getJson('/api/exports/'.$token)
            ->assertOk()
            ->assertJsonPath('data.download_url', null);
        $this->actingAs($owner)->get('/api/exports/'.$token.'/download')->assertGone();
    }

    public function test_download_rejects_a_path_that_does_not_match_the_owner_token_and_format(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $token = (string) Str::uuid();
        $path = 'exports/'.$owner->id.'/different-token.csv';
        ExportArtifact::query()->create([
            'user_id' => $owner->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => $path,
            'status' => 'ready',
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($path, 'wrong artifact');

        $this->actingAs($owner)->get('/api/exports/'.$token.'/download')->assertNotFound();
    }
}
