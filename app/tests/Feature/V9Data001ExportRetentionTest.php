<?php

namespace Tests\Feature;

use App\Models\ExportArtifact;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class V9Data001ExportRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_deletes_expired_artifacts_and_files_but_keeps_unexpired_and_in_progress_records(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $now = Carbon::parse('2026-10-08 12:00:00');
        Carbon::setTestNow($now);

        try {
            $expired = $this->artifact($user, $now->copy()->subSecond());
            $atExpiry = $this->artifact($user, $now->copy());
            $valid = $this->artifact($user, $now->copy()->addDay());
            $queued = $this->artifact($user, null, 'queued');
            foreach ([$expired, $atExpiry, $valid, $queued] as $artifact) {
                Storage::disk('local')->put($artifact->path, $artifact->status);
            }

            $this->artisan('portfolio:purge-export-artifacts')->assertExitCode(0);

            $this->assertDatabaseMissing('portfolio_export_artifacts', ['id' => $expired->id]);
            $this->assertDatabaseMissing('portfolio_export_artifacts', ['id' => $atExpiry->id]);
            $this->assertDatabaseHas('portfolio_export_artifacts', ['id' => $valid->id]);
            $this->assertDatabaseHas('portfolio_export_artifacts', ['id' => $queued->id]);
            Storage::disk('local')->assertMissing($expired->path);
            Storage::disk('local')->assertMissing($atExpiry->path);
            Storage::disk('local')->assertExists($valid->path);
            Storage::disk('local')->assertExists($queued->path);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function artifact(User $user, ?Carbon $expiresAt, string $status = 'ready'): ExportArtifact
    {
        $token = (string) Str::uuid();
        return ExportArtifact::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => 'exports/'.$user->id.'/'.$token.'.csv',
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }
}
