<?php

namespace Tests\Feature;

use App\Models\ExportArtifact;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class V9Data001ExportSynchronousFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_size_limit_failure_cleans_the_partial_file_and_sets_terminal_retention_expiry(): void
    {
        Storage::fake('local');
        config(['exports.max_file_bytes' => 1]);
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
        $frozenNow = Carbon::parse('2026-10-08 12:00:00');
        Carbon::setTestNow($frozenNow);
        try {
            $this->actingAs($owner)->withProfileHeader($owner, $profile)->postJson('/api/exports', [
                'dataset' => 'portfolio-snapshots',
                'format' => 'csv',
                'scope' => 'full',
                'fields' => ['snapshot_date', 'portfolio_value'],
            ])->assertUnprocessable()->assertJsonPath('message', 'Export exceeds the maximum file size. Narrow the scope and try again.');

            $artifact = ExportArtifact::query()->where('user_id', $owner->id)->sole();
            $this->assertSame('failed', $artifact->status);
            $this->assertSame($frozenNow->copy()->addDay()->toDateTimeString(), $artifact->expires_at->toDateTimeString());
            Storage::disk('local')->assertMissing($artifact->path);
        } finally {
            Carbon::setTestNow();
        }
    }
}
